import './bootstrap';

function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B';

  const unit = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
  const index = Math.min(Math.floor(Math.log(bytes) / Math.log(unit)), sizes.length - 1);
  const value = bytes / Math.pow(unit, index);

  return `${Math.round(value * 100) / 100} ${sizes[index]}`;
}

/**
 * PHP drops any file past `max_file_uploads` (20 by default) without reporting
 * an error, and a whole folder in one request also runs into `post_max_size`.
 * Uploads are therefore split into batches, matching what the CLI already does.
 */
const BATCH_MAX_FILES = 20;
const BATCH_MAX_BYTES = 24 * 1024 * 1024;

interface DirectoryEntry {
  file: File;
  path: string;
}

interface UploadBatch {
  entries: DirectoryEntry[];
  bytes: number;
}

function directoryFileInputs(form: HTMLFormElement): HTMLInputElement[] {
  return [...form.querySelectorAll<HTMLInputElement>(
    'input[type="file"][name="files"], input[type="file"][name="files[]"]'
  )];
}

function collectDirectoryEntries(form: HTMLFormElement): DirectoryEntry[] {
  const entries: DirectoryEntry[] = [];

  directoryFileInputs(form).forEach((input) => {
    [...(input.files ?? [])].forEach((file) => {
      entries.push({ file, path: file.webkitRelativePath || file.name });
    });
  });

  return entries;
}

function planBatches(entries: DirectoryEntry[]): UploadBatch[] {
  const batches: UploadBatch[] = [];
  let current: UploadBatch = { entries: [], bytes: 0 };

  entries.forEach((entry) => {
    const wouldExceed = current.entries.length >= BATCH_MAX_FILES
      || (current.entries.length > 0 && current.bytes + entry.file.size > BATCH_MAX_BYTES);

    if (wouldExceed) {
      batches.push(current);
      current = { entries: [], bytes: 0 };
    }

    current.entries.push(entry);
    current.bytes += entry.file.size;
  });

  if (current.entries.length > 0) {
    batches.push(current);
  }

  return batches;
}

/**
 * Form fields other than the files themselves, so each batch carries the name,
 * password, expiry and CSRF token.
 */
function baseFieldsOf(form: HTMLFormElement): FormData {
  const formData = new FormData(form);

  formData.delete('paths');
  formData.delete('paths[]');
  formData.delete('files');
  formData.delete('files[]');

  return formData;
}

function buildBatchFormData(form: HTMLFormElement, batch: UploadBatch): FormData {
  const formData = baseFieldsOf(form);

  batch.entries.forEach((entry) => {
    formData.append('files[]', entry.file);
    formData.append('paths[]', entry.path);
  });

  return formData;
}

function buildUploadFormData(form: HTMLFormElement): FormData {
  const formData = new FormData(form);

  if (!form.hasAttribute('data-directory-upload')) {
    return formData;
  }

  formData.delete('paths');
  formData.delete('paths[]');

  collectDirectoryEntries(form).forEach((entry) => {
    formData.append('paths[]', entry.path);
  });

  return formData;
}

interface UploadResponse {
  status: number;
  body: string;
}

/**
 * Files at or above this size are sent through the resumable endpoint instead
 * of one large request, so a dropped connection costs one chunk rather than the
 * whole upload.
 */
const RESUMABLE_THRESHOLD = 8 * 1024 * 1024;

/** Retries per chunk before giving up, with the offset re-synced each time. */
const CHUNK_RETRIES = 3;

interface ChunkedOptions {
  file: File;
  csrfToken: string;
  password?: string;
  expires?: string;
  maxDownloads?: string;
  onProgress: (loaded: number, total: number) => void;
  onStatus: (message: string) => void;
}

function csrfTokenOf(form: HTMLFormElement): string {
  const field = form.querySelector<HTMLInputElement>('input[name="_token"]');

  if (field?.value) {
    return field.value;
  }

  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

async function postJson(url: string, body: FormData, csrfToken: string): Promise<UploadResponse> {
  body.append('_token', csrfToken);

  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    body,
  });

  return { status: response.status, body: await response.text() };
}

/**
 * Upload one file through the resumable endpoint.
 *
 * Returns the finished share URL, or throws with a message suitable for
 * display. On a network failure the current offset is re-read from the server
 * and the chunk is retried from there, so progress already made is not lost.
 */
async function uploadResumable(options: ChunkedOptions): Promise<string> {
  const { file, csrfToken } = options;

  const init = new FormData();
  init.append('name', file.name);
  init.append('size', String(file.size));

  if (file.type) {
    init.append('mime', file.type);
  }

  if (options.password) init.append('password', options.password);
  if (options.expires) init.append('expires', options.expires);
  if (options.maxDownloads) init.append('max_downloads', options.maxDownloads);

  const created = await postJson('/f/chunk', init, csrfToken);

  if (created.status !== 201) {
    throw new Error(errorFromBody(created.status, created.body));
  }

  const session = parseJson(created.body);
  const token = String(session.token ?? '');
  const chunkSize = Number(session.chunk_size) || 4 * 1024 * 1024;

  let offset = Number(session.offset) || 0;

  while (offset < file.size) {
    const slice = file.slice(offset, Math.min(offset + chunkSize, file.size));
    const body = new FormData();
    body.append('offset', String(offset));
    body.append('chunk', slice);

    let sent: UploadResponse | null = null;
    let lastError = '';

    for (let attempt = 0; attempt < CHUNK_RETRIES; attempt++) {
      try {
        sent = await postJson(`/f/chunk/${token}`, body, csrfToken);
      }
      catch {
        lastError = 'Connection lost.';
        sent = null;
      }

      if (sent && sent.status >= 200 && sent.status < 300) {
        break;
      }

      // 409 means the server and client disagree about the offset, which is
      // exactly the case after an interrupted attempt: resync and continue.
      if (sent && sent.status === 409) {
        const resumeAt = Number(parseJson(sent.body).expected);

        if (Number.isFinite(resumeAt)) {
          offset = resumeAt;
          sent = null;
          break;
        }
      }

      if (sent && sent.status !== 409) {
        throw new Error(errorFromBody(sent.status, sent.body));
      }

      options.onStatus(`Retrying... (${attempt + 1}/${CHUNK_RETRIES})`);
      await new Promise((resolve) => setTimeout(resolve, 500 * (attempt + 1)));
    }

    if (!sent) {
      // Either a resync happened or every retry failed; ask the server where
      // it stands before deciding.
      const status = await fetch(`/f/chunk/${token}`, {
        headers: { 'Accept': 'application/json' },
      }).then((r) => r.ok ? r.json() : null).catch(() => null);

      if (!status) {
        throw new Error(lastError || 'Upload failed.');
      }

      offset = Number(status.offset) || 0;
      continue;
    }

    offset = Number(parseJson(sent.body).offset) || offset + slice.size;
    options.onProgress(offset, file.size);
  }

  options.onStatus('Finalising upload...');

  const finished = await postJson(`/f/chunk/${token}/complete`, new FormData(), csrfToken);

  if (finished.status !== 201) {
    throw new Error(errorFromBody(finished.status, finished.body));
  }

  return String(parseJson(finished.body).url ?? '');
}

/**
 * XMLHttpRequest rather than fetch, because only XHR reports upload progress.
 */
function sendUpload(
  url: string,
  formData: FormData,
  onProgress: (loaded: number, total: number) => void
): Promise<UploadResponse> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();

    xhr.upload.addEventListener('progress', (event) => {
      if (event.lengthComputable) {
        onProgress(event.loaded, event.total);
      }
    });

    xhr.addEventListener('load', () => resolve({ status: xhr.status, body: xhr.responseText }));
    xhr.addEventListener('error', () => reject(new Error('network')));
    xhr.addEventListener('abort', () => reject(new Error('aborted')));

    xhr.open('POST', url);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.send(formData);
  });
}

function parseJson(body: string): Record<string, unknown> {
  try {
    return JSON.parse(body) as Record<string, unknown>;
  }
  catch {
    return {};
  }
}

function errorFromBody(status: number, body: string): string {
  const payload = parseJson(body);

  if (typeof payload.error === 'string') {
    return payload.error;
  }

  if (typeof payload.message === 'string' && payload.message) {
    return payload.message;
  }

  const errors = payload.errors as Record<string, string[]> | undefined;

  if (errors) {
    const first = Object.values(errors)[0]?.[0];

    if (first) {
      return first;
    }
  }

  if (status === 413) {
    return 'Upload rejected: too large, or over your storage quota.';
  }

  if (status === 419) {
    return 'Session expired. Reload the page and try again.';
  }

  return 'Upload failed. Please try again.';
}

async function refreshPageSection(selector: string | null): Promise<void> {
  if (!selector) {
    return;
  }

  const currentSection = document.querySelector(selector);
  if (!currentSection) {
    return;
  }

  const response = await fetch(window.location.href, {
    headers: {
      'Accept': 'text/html',
      'X-Requested-With': 'XMLHttpRequest',
    },
  });

  if (!response.ok) {
    return;
  }

  const html = await response.text();
  const nextDocument = new DOMParser().parseFromString(html, 'text/html');
  const nextSection = nextDocument.querySelector(selector);

  if (nextSection) {
    currentSection.replaceWith(nextSection);
    initializeInteractions(nextSection);
  }
}

function initializeInteractions(root: ParentNode = document): void {
  const copyToClipboardElements = [...root.querySelectorAll<HTMLElement>('[data-clipboard-text]')];
  copyToClipboardElements.forEach((element) => {
    if (element.hasAttribute('data-clipboard-ready')) {
      return;
    }

    element.setAttribute('data-clipboard-ready', '1');

    element.addEventListener('click', (event) => {
      event.preventDefault();
      const text = element.getAttribute('data-clipboard-text') ?? '';
      if (navigator.clipboard) {
        navigator.clipboard.writeText(text);
      }
      else {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
      }

      const pointerEvent = event as MouseEvent;
      const floatingElement = document.createElement('div');
      floatingElement.classList.add('copy-feedback');
      floatingElement.innerText = 'Copied';
      floatingElement.style.pointerEvents = 'none';
      floatingElement.style.position = 'absolute';
      floatingElement.style.top = `${pointerEvent.clientY}px`;
      floatingElement.style.left = `${pointerEvent.clientX}px`;

      document.body.appendChild(floatingElement);
      let opacity = 1;
      const int = window.setInterval(() => {
        floatingElement.style.top = `${floatingElement.offsetTop - 1}px`;
        floatingElement.style.opacity = `${opacity = opacity - 0.01}`;
        if (opacity <= 0) {
          document.body.removeChild(floatingElement);
          window.clearInterval(int);
        }
      }, 10);
    });
  });

  const uploadProgressForms = [...root.querySelectorAll<HTMLFormElement>('form[data-upload-progress]')];
  uploadProgressForms.forEach((form) => {
    if (form.hasAttribute('data-upload-progress-ready')) {
      return;
    }

    form.setAttribute('data-upload-progress-ready', '1');

    form.addEventListener('submit', async (event) => {
      event.preventDefault();

      if (!form.reportValidity()) {
        return;
      }

      const scope = form.closest('[data-upload-scope]') ?? document;
      const refreshTarget = form.getAttribute('data-upload-refresh-target');
      const progressContainer = scope.querySelector<HTMLElement>('[data-upload-progress-container]');
      const progressBar = scope.querySelector<HTMLElement>('[data-upload-progress-bar]');
      const progressPercent = scope.querySelector<HTMLElement>('[data-upload-progress-percent]');
      const progressStatus = scope.querySelector<HTMLElement>('[data-upload-progress-status]');
      const submitButton = form.querySelector<HTMLButtonElement>('[data-upload-submit]') || form.querySelector<HTMLButtonElement>('[type="submit"]');
      const result = scope.querySelector<HTMLElement>('[data-upload-result]');
      const resultUrl = scope.querySelector<HTMLInputElement>('[data-upload-result-url]');
      const resultCopy = scope.querySelector<HTMLElement>('[data-upload-result-copy]');
      const originalSubmitText = submitButton?.textContent;

      progressContainer?.classList.remove('hidden');
      result?.classList.add('hidden');

      if (progressBar) {
        progressBar.style.width = '0%';
        progressBar.classList.remove('progress-bar--error');
      }

      if (progressPercent) {
        progressPercent.textContent = '0%';
      }

      if (progressStatus) {
        progressStatus.textContent = 'Preparing upload...';
      }

      if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = 'Uploading...';
      }

      const action = form.getAttribute('data-upload-action') || form.action;

      const setProgress = (loaded: number, total: number) => {
        const percentComplete = total > 0 ? Math.round((loaded / total) * 100) : 0;

        if (progressBar) {
          progressBar.style.width = `${percentComplete}%`;
        }

        if (progressPercent) {
          progressPercent.textContent = `${percentComplete}%`;
        }

        if (progressStatus) {
          progressStatus.textContent = percentComplete < 100
            ? `Uploading... ${formatBytes(loaded)} of ${formatBytes(total)}`
            : 'Processing upload...';
        }
      };

      const fail = (message: string) => {
        if (progressBar) {
          progressBar.classList.add('progress-bar--error');
        }

        if (progressStatus) {
          progressStatus.textContent = message;
        }

        if (submitButton) {
          submitButton.disabled = false;
          submitButton.textContent = originalSubmitText || 'Upload File';
        }
      };

      const succeed = (uploadedUrl: string) => {
        if (progressBar) {
          progressBar.style.width = '100%';
        }

        if (progressPercent) {
          progressPercent.textContent = '100%';
        }

        if (progressStatus) {
          progressStatus.textContent = 'Upload complete.';
        }

        if (uploadedUrl) {
          if (resultUrl) {
            resultUrl.value = uploadedUrl;
          }

          if (resultCopy) {
            resultCopy.setAttribute('data-clipboard-text', uploadedUrl);
          }

          result?.classList.remove('hidden');
        }

        refreshPageSection(refreshTarget).catch(() => {
          if (progressStatus) {
            progressStatus.textContent = 'Upload complete. Refresh the page to update the file list.';
          }
        });

        form.reset();

        if (submitButton) {
          submitButton.disabled = false;
          submitButton.textContent = originalSubmitText || 'Upload File';
        }
      };

      const isDirectoryUpload = form.hasAttribute('data-directory-upload');
      const entries = isDirectoryUpload ? collectDirectoryEntries(form) : [];
      const batches = isDirectoryUpload ? planBatches(entries) : [];

      // A large single file goes through the resumable endpoint, so a dropped
      // connection costs one chunk instead of the entire transfer.
      const singleInput = isDirectoryUpload
        ? null
        : form.querySelector<HTMLInputElement>('input[type="file"][name="file"]');
      const singleFile = singleInput?.files?.[0] ?? null;

      if (singleFile && singleFile.size >= RESUMABLE_THRESHOLD) {
        try {
          const url = await uploadResumable({
            file: singleFile,
            csrfToken: csrfTokenOf(form),
            password: form.querySelector<HTMLInputElement>('[name="password"]')?.value || undefined,
            expires: form.querySelector<HTMLSelectElement>('[name="expires"]')?.value || undefined,
            maxDownloads: form.querySelector<HTMLInputElement>('[name="max_downloads"]')?.value || undefined,
            onProgress: setProgress,
            onStatus: (message) => {
              if (progressStatus) {
                progressStatus.textContent = message;
              }
            },
          });

          succeed(url);
        }
        catch (error) {
          fail(error instanceof Error ? error.message : 'Upload failed. Please try again.');
        }

        return;
      }

      // A single request is enough for ordinary uploads and for small folders.
      if (!isDirectoryUpload || batches.length <= 1) {
        try {
          const response = await sendUpload(action, buildUploadFormData(form), setProgress);

          if (response.status >= 200 && response.status < 300) {
            succeed(String(parseJson(response.body).url ?? ''));
          }
          else {
            fail(errorFromBody(response.status, response.body));
          }
        }
        catch {
          fail('Network error. Please try again.');
        }

        return;
      }

      // Folder upload: send in batches, keeping one progress bar across them.
      const totalBytes = entries.reduce((sum, entry) => sum + entry.file.size, 0);
      let bytesSent = 0;
      let uploadedCount = 0;
      let targetUrl = action;
      let resultUrlValue = '';

      for (let index = 0; index < batches.length; index++) {
        const batch = batches[index];

        if (progressStatus) {
          progressStatus.textContent = `Uploading batch ${index + 1} of ${batches.length}...`;
        }

        let response: UploadResponse;

        try {
          response = await sendUpload(
            targetUrl,
            buildBatchFormData(form, batch),
            (loaded) => setProgress(bytesSent + loaded, totalBytes)
          );
        }
        catch {
          fail(`Network error after ${uploadedCount} of ${entries.length} file(s).`);

          return;
        }

        if (response.status < 200 || response.status >= 300) {
          fail(`${errorFromBody(response.status, response.body)} (${uploadedCount} of ${entries.length} file(s) uploaded)`);

          return;
        }

        const payload = parseJson(response.body);

        // The server reports how many files it actually stored. If that is
        // short of what was sent, files were dropped and saying "complete"
        // would be a lie.
        const stored = typeof payload.uploaded === 'number' ? payload.uploaded : batch.entries.length;
        uploadedCount += stored;

        if (stored < batch.entries.length) {
          fail(`The server stored ${uploadedCount} of ${entries.length} file(s). Try uploading the remainder again.`);

          return;
        }

        if (typeof payload.url === 'string' && !resultUrlValue) {
          resultUrlValue = payload.url;
        }

        // The first request creates the directory; the rest add to it.
        const shortCode = payload.short_code;

        if (typeof shortCode === 'string' && shortCode) {
          targetUrl = `/d/${shortCode}/-/upload`;
        }

        bytesSent += batch.bytes;
      }

      succeed(resultUrlValue);
    });
  });
}

window.addEventListener('load', () => initializeInteractions());