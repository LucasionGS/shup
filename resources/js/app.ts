import './bootstrap';

function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B';

  const unit = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
  const index = Math.min(Math.floor(Math.log(bytes) / Math.log(unit)), sizes.length - 1);
  const value = bytes / Math.pow(unit, index);

  return `${Math.round(value * 100) / 100} ${sizes[index]}`;
}

function getUploadError(xhr: XMLHttpRequest): string {
  try {
    const response = JSON.parse(xhr.responseText) as {
      message?: unknown;
      errors?: Record<string, unknown>;
    };

    if (typeof response.message === 'string') {
      return response.message;
    }

    if (response.errors) {
      const firstError = Object.values(response.errors)
        .flatMap((value) => Array.isArray(value) ? value : [value])
        .find((value): value is string => typeof value === 'string');

      if (firstError) {
        return firstError;
      }
    }
  }
  catch {
    // Fall through to the generic message when the server returns HTML.
  }

  return 'Upload failed. Please try again.';
}

function buildUploadFormData(form: HTMLFormElement): FormData {
  const formData = new FormData(form);

  if (!form.hasAttribute('data-directory-upload')) {
    return formData;
  }

  formData.delete('paths');
  formData.delete('paths[]');

  const fileInputs = [...form.querySelectorAll<HTMLInputElement>('input[type="file"][name="files"], input[type="file"][name="files[]"]')];

  fileInputs.forEach((input) => {
    [...(input.files ?? [])].forEach((file) => {
      formData.append('paths[]', file.webkitRelativePath || file.name);
    });
  });

  return formData;
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

    form.addEventListener('submit', (event) => {
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

      const xhr = new XMLHttpRequest();

      xhr.upload.addEventListener('progress', (progressEvent) => {
        if (!progressEvent.lengthComputable) {
          if (progressStatus) {
            progressStatus.textContent = 'Uploading...';
          }

          return;
        }

        const percentComplete = Math.round((progressEvent.loaded / progressEvent.total) * 100);

        if (progressBar) {
          progressBar.style.width = `${percentComplete}%`;
        }

        if (progressPercent) {
          progressPercent.textContent = `${percentComplete}%`;
        }

        if (progressStatus) {
          progressStatus.textContent = percentComplete < 100
            ? `Uploading... ${formatBytes(progressEvent.loaded)} of ${formatBytes(progressEvent.total)}`
            : 'Processing upload...';
        }
      });

      xhr.addEventListener('load', () => {
        if (xhr.status >= 200 && xhr.status < 300) {
          let uploadedUrl = '';

          try {
            uploadedUrl = (JSON.parse(xhr.responseText) as { url?: string }).url || '';
          }
          catch {
            uploadedUrl = '';
          }

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
        }
        else {
          if (progressBar) {
            progressBar.classList.add('progress-bar--error');
          }

          if (progressStatus) {
            progressStatus.textContent = getUploadError(xhr);
          }
        }

        if (submitButton) {
          submitButton.disabled = false;
          submitButton.textContent = originalSubmitText || 'Upload File';
        }
      });

      xhr.addEventListener('error', () => {
        if (progressBar) {
          progressBar.classList.add('progress-bar--error');
        }

        if (progressStatus) {
          progressStatus.textContent = 'Network error. Please try again.';
        }

        if (submitButton) {
          submitButton.disabled = false;
          submitButton.textContent = originalSubmitText || 'Upload File';
        }
      });

      xhr.open('POST', form.getAttribute('data-upload-action') || form.action);
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.send(buildUploadFormData(form));
    });
  });
}

window.addEventListener('load', () => initializeInteractions());