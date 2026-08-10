#!/usr/bin/env -S deno run --allow-net --allow-read --allow-write --allow-env --allow-run
/**
 * shup - command line client for a Shup instance.
 *
 *   shup path/myfile.png
 *   shup -t "This is my full text yada yada"
 *   shup -tf path/myfile.txt
 *   shup -d path/mydirectory/
 *   shup -s https://myurl.com [shortcode]
 */
const VERSION = "1.0.0";

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

interface Config {
  /**
   * The API key used to authenticate with the Shup API. This is required for all requests to the API.
   */
  apiKey: string;
  /**
   * The URL of the Shup API. This is used to send requests to the API.
   */
  url: string;
}

const env = (name: string) => Deno.env.get(name);

// User config dir
const CONFIG_FILE = (
  env("SHUP_CONFIG") ? env("SHUP_CONFIG")! :
  env("XDG_CONFIG_HOME") ?
  env("XDG_CONFIG_HOME") + "/shup/config.json" :
  env("HOME") + "/.config/shup/config.json"
);

/** An expected failure. The message is shown to the user without a stack trace. */
class CliError extends Error {
  constructor(message: string, readonly hint?: string) {
    super(message);
  }
}

function readConfigFile(): Partial<Config> {
  let raw: string;
  try {
    raw = Deno.readTextFileSync(CONFIG_FILE);
  } catch (error) {
    if (error instanceof Deno.errors.NotFound) return {};
    throw new CliError(`Could not read ${CONFIG_FILE}: ${(error as Error).message}`);
  }

  try {
    return JSON.parse(raw) as Partial<Config>;
  } catch {
    throw new CliError(
      `${CONFIG_FILE} is not valid JSON.`,
      "Fix it by hand, or run `shup config init` to write a fresh one.",
    );
  }
}

function writeConfigFile(config: Partial<Config>): void {
  const dir = CONFIG_FILE.slice(0, CONFIG_FILE.lastIndexOf("/"));
  Deno.mkdirSync(dir, { recursive: true });
  Deno.writeTextFileSync(CONFIG_FILE, JSON.stringify(config, null, 2) + "\n");
  try {
    Deno.chmodSync(CONFIG_FILE, 0o600);
  } catch {
    // chmod is unsupported on some platforms; the file is still written.
  }
}

function normalizeUrl(url: string): string {
  let value = url.trim().replace(/\/+$/, "");
  if (!/^https?:\/\//i.test(value)) value = "https://" + value;
  return value;
}

/**
 * Config file, overridden by environment variables, overridden by CLI flags.
 * With nothing configured at all, walks the user through the setup once.
 */
async function resolveConfig(overrides: { url?: string; apiKey?: string }): Promise<Config> {
  const file = readConfigFile();
  const url = overrides.url ?? env("SHUP_URL") ?? file.url;
  const apiKey = overrides.apiKey ?? env("SHUP_API_KEY") ?? file.apiKey;

  if (!url) {
    if (!Deno.stdin.isTerminal()) {
      throw new CliError(
        "No Shup server configured.",
        "Run `shup config init`, or pass --url https://your.shup.instance",
      );
    }
    const created = await runSetup(file);
    return { ...created, apiKey: apiKey ?? created.apiKey };
  }

  return { url: normalizeUrl(url), apiKey: apiKey ?? "" };
}

// ---------------------------------------------------------------------------
// Terminal output
// ---------------------------------------------------------------------------

const encoder = new TextEncoder();
const decoder = new TextDecoder();

const term = {
  color: Deno.stderr.isTerminal() && !env("NO_COLOR"),
  tty: Deno.stderr.isTerminal(),
  quiet: false,
};

function style(code: string) {
  return (text: string) => (term.color ? `\x1b[${code}m${text}\x1b[0m` : text);
}

const c = {
  bold: style("1"),
  red: style("31"),
  green: style("32"),
  yellow: style("33"),
  blue: style("34"),
  magenta: style("35"),
  cyan: style("36"),
  gray: style("90"),
};

function writeErr(text: string) {
  Deno.stderr.writeSync(encoder.encode(text));
}

function writeOut(text: string) {
  Deno.stdout.writeSync(encoder.encode(text));
}

/** Status output goes to stderr so `shup file.png | pbcopy` stays clean. */
function info(text: string) {
  if (!term.quiet) writeErr(text + "\n");
}

function warn(text: string) {
  writeErr(`  ${c.yellow("!")} ${text}\n`);
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  const units = ["KB", "MB", "GB", "TB"];
  let value = bytes / 1024;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit++;
  }
  return `${value < 10 ? value.toFixed(1) : Math.round(value)} ${units[unit]}`;
}

function formatDuration(minutes: number): string {
  const parts: string[] = [];
  const days = Math.floor(minutes / 1440);
  const hours = Math.floor((minutes % 1440) / 60);
  const rest = minutes % 60;
  if (days) parts.push(`${days}d`);
  if (hours) parts.push(`${hours}h`);
  if (rest) parts.push(`${rest}m`);
  return parts.join(" ") || "0m";
}

const SPINNER = ["⠋", "⠙", "⠹", "⠸", "⠼", "⠴", "⠦", "⠧", "⠇", "⠏"];

/** A single line spinner and progress bar on stderr. Silent when not a TTY. */
class Activity {
  #frame = 0;
  #timer: ReturnType<typeof setInterval> | undefined;
  #label: string;
  #sent = 0;
  #total = 0;
  #startedAt = performance.now();
  #lastDraw = 0;
  #live: boolean;

  constructor(label: string) {
    this.#label = label;
    this.#live = term.tty && !term.quiet;
    if (this.#live) {
      this.#timer = setInterval(() => this.#draw(true), 90);
      this.#draw(true);
    } else if (!term.quiet) {
      writeErr(`  ${c.gray(label)}\n`);
    }
  }

  progress(sent: number, total: number) {
    this.#sent = sent;
    this.#total = total;
    this.#draw(false);
  }

  stop() {
    if (this.#timer !== undefined) clearInterval(this.#timer);
    this.#timer = undefined;
    if (this.#live) writeErr("\r\x1b[2K");
  }

  #draw(force: boolean) {
    if (!this.#live) return;
    const now = performance.now();
    if (!force && now - this.#lastDraw < 60) return;
    this.#lastDraw = now;

    let line = `${c.cyan(SPINNER[this.#frame++ % SPINNER.length])} ${this.#label}`;

    if (this.#total > 0) {
      const ratio = Math.min(1, this.#sent / this.#total);
      const width = 18;
      const filled = Math.round(ratio * width);
      const bar = "█".repeat(filled) + "░".repeat(width - filled);
      const elapsed = (now - this.#startedAt) / 1000;
      const rate = elapsed > 0.25 ? `  ${formatBytes(this.#sent / elapsed)}/s` : "";
      line += `  ${c.blue(bar)} ${String(Math.round(ratio * 100)).padStart(3)}%  ` +
        c.gray(`${formatBytes(this.#sent)}/${formatBytes(this.#total)}${rate}`);
    }

    writeErr(`\r\x1b[2K  ${line}`);
  }
}

async function promptSecret(message: string): Promise<string> {
  if (!Deno.stdin.isTerminal()) {
    throw new CliError("Cannot prompt for a password: stdin is not a terminal.");
  }
  writeErr(message);
  Deno.stdin.setRaw(true);
  try {
    const buffer = new Uint8Array(64);
    let value = "";
    while (true) {
      const read = await Deno.stdin.read(buffer);
      if (read === null) break;
      const chunk = buffer.subarray(0, read);
      if (chunk.includes(3)) {
        writeErr("\n");
        Deno.exit(130);
      }
      let done = false;
      for (const char of decoder.decode(chunk)) {
        if (char === "\r" || char === "\n") {
          done = true;
          break;
        }
        if (char === "\x7f" || char === "\b") value = value.slice(0, -1);
        else value += char;
      }
      if (done) break;
    }
    return value;
  } finally {
    Deno.stdin.setRaw(false);
    writeErr("\n");
  }
}

// ---------------------------------------------------------------------------
// Expiry parsing
// ---------------------------------------------------------------------------

const DATE_TIME = /^(\d{1,2})-(\d{1,2})-(\d{4})(?:[ T]+(\d{1,2})(?::(\d{1,2}))?)?$/;
const CLOCK = /^(\d+)(?::(\d{1,2}))?(?::(\d{1,2}))?$/;
const RELATIVE = /^(?:(\d+)\s*w)?\s*(?:(\d+)\s*d)?\s*(?:(\d+)\s*h)?\s*(?:(\d+)\s*m)?$/i;

/**
 * Resolves an `--expires` value to a number of minutes from now. Minutes are
 * the smallest unit the API stores.
 *
 *   "90"                90 minutes
 *   "2:30"              2 hours 30 minutes
 *   "1:12:30"           1 day 12 hours 30 minutes
 *   "24-12-2026"        midnight on that date
 *   "24-12-2026 18:30"  that date and time of day
 *   "2d12h"             relative shorthand
 */
function parseExpiry(spec: string, now = new Date()): number {
  const value = spec.trim();
  if (!value) throw new CliError("Empty --expires value.");

  const date = DATE_TIME.exec(value);
  if (date) {
    const [, dd, mm, yyyy, hh = "0", min = "0"] = date;
    const target = new Date(Number(yyyy), Number(mm) - 1, Number(dd), Number(hh), Number(min));
    if (target.getDate() !== Number(dd) || target.getMonth() !== Number(mm) - 1) {
      throw new CliError(`"${spec}" is not a real date.`);
    }
    const minutes = Math.round((target.getTime() - now.getTime()) / 60000);
    if (minutes <= 0) throw new CliError(`"${spec}" is less than a minute away.`);
    return minutes;
  }

  const clock = CLOCK.exec(value);
  if (clock) {
    const groups = clock.slice(1).filter((part) => part !== undefined).map(Number);
    const [d, h, m] = groups.length === 3
      ? groups
      : groups.length === 2
      ? [0, groups[0], groups[1]]
      : [0, 0, groups[0]];
    return d * 1440 + h * 60 + m;
  }

  const relative = RELATIVE.exec(value);
  if (relative && relative.slice(1).some((part) => part !== undefined)) {
    const [w, d, h, m] = relative.slice(1).map((part) => Number(part ?? 0));
    return w * 10080 + d * 1440 + h * 60 + m;
  }

  throw new CliError(
    `Could not understand --expires "${spec}".`,
    'Use minutes ("90"), "h:m", "d:h:m", "DD-MM-YYYY [h:m]" or shorthand like "2d12h".',
  );
}

/** The API treats 0 as "never expires", so a valid expiry is at least one minute. */
function validateExpiry(minutes: number | undefined): number | undefined {
  if (minutes === undefined) return undefined;
  if (minutes < 1) throw new CliError("An expiry must be at least one minute.");
  return minutes;
}

// ---------------------------------------------------------------------------
// Multipart requests
// ---------------------------------------------------------------------------

type Part =
  | { kind: "field"; name: string; value: string }
  | { kind: "file"; name: string; filename: string; path: string; size: number; mime: string }
  | { kind: "bytes"; name: string; filename: string; data: Uint8Array; mime: string };

/** Bodies larger than this are streamed from disk instead of buffered in memory. */
const STREAM_THRESHOLD = 64 * 1024 * 1024;

function escapeHeaderValue(value: string): string {
  return value.replace(/["\r\n]/g, "_");
}

function partHeader(boundary: string, part: Part): Uint8Array {
  const disposition = part.kind === "field"
    ? `form-data; name="${escapeHeaderValue(part.name)}"`
    : `form-data; name="${escapeHeaderValue(part.name)}"; filename="${escapeHeaderValue(part.filename)}"`;
  const type = part.kind === "field" ? "" : `Content-Type: ${part.mime}\r\n`;
  return encoder.encode(`--${boundary}\r\nContent-Disposition: ${disposition}\r\n${type}\r\n`);
}

function partBodySize(part: Part): number {
  if (part.kind === "field") return encoder.encode(part.value).byteLength;
  if (part.kind === "bytes") return part.data.byteLength;
  return part.size;
}

type Progress = (sent: number, total: number) => void;

function buildMultipart(parts: Part[], onProgress?: Progress) {
  const random = crypto.getRandomValues(new Uint8Array(12));
  const boundary = "ShupBoundary" + Array.from(random, (b) => b.toString(16).padStart(2, "0")).join("");
  const trailer = encoder.encode(`--${boundary}--\r\n`);
  const crlf = encoder.encode("\r\n");

  const headers = parts.map((part) => partHeader(boundary, part));
  const length = trailer.byteLength + headers.reduce(
    (total, header, index) => total + header.byteLength + partBodySize(parts[index]) + crlf.byteLength,
    0,
  );

  const contentType = `multipart/form-data; boundary=${boundary}`;
  const oversized = parts.some((part) => part.kind === "file" && part.size > STREAM_THRESHOLD);

  if (!oversized) {
    const buffer = new Uint8Array(length);
    let offset = 0;
    const push = (chunk: Uint8Array) => {
      buffer.set(chunk, offset);
      offset += chunk.byteLength;
    };

    parts.forEach((part, index) => {
      push(headers[index]);
      if (part.kind === "field") push(encoder.encode(part.value));
      else if (part.kind === "bytes") push(part.data);
      else push(Deno.readFileSync(part.path));
      push(crlf);
    });
    push(trailer);
    onProgress?.(length, length);

    return { contentType, body: buffer as BodyInit };
  }

  let sent = 0;
  const body = new ReadableStream<Uint8Array>({
    async pull(controller) {
      for (const [index, part] of parts.entries()) {
        controller.enqueue(headers[index]);
        sent += headers[index].byteLength;

        if (part.kind === "field") {
          const chunk = encoder.encode(part.value);
          controller.enqueue(chunk);
          sent += chunk.byteLength;
        } else if (part.kind === "bytes") {
          controller.enqueue(part.data);
          sent += part.data.byteLength;
        } else {
          const file = await Deno.open(part.path, { read: true });
          for await (const chunk of file.readable) {
            controller.enqueue(chunk);
            sent += chunk.byteLength;
            onProgress?.(sent, length);
          }
        }

        controller.enqueue(crlf);
        sent += crlf.byteLength;
        onProgress?.(sent, length);
      }
      controller.enqueue(trailer);
      controller.close();
    },
  });

  return { contentType, body: body as BodyInit };
}

interface UploadResponse {
  url: string;
  short_code: string;
  uploaded?: number;
}

const STATUS_HINTS: Record<number, string> = {
  401: "This server does not allow anonymous uploads. Store an API key with `shup config init`.",
  403: "Your API key is not allowed to do that.",
  404: "Check the server URL with `shup config show`.",
  413: "The server rejected the upload as too large (upload_max_filesize / post_max_size).",
  419: "The server rejected the request as a CSRF failure.",
};

function describeHttpError(status: number, bodyText: string): CliError {
  let payload: Record<string, unknown> | undefined;
  try {
    payload = JSON.parse(bodyText);
  } catch {
    // Not JSON, most likely an HTML error page.
  }

  if (payload) {
    const errors = payload.errors as Record<string, string[]> | undefined;
    if (errors) {
      const details = Object.entries(errors)
        .map(([field, messages]) => `${field}: ${messages.join(", ")}`)
        .join("; ");
      return new CliError(`Server rejected the request (${status}): ${details}`, STATUS_HINTS[status]);
    }
    const message = (payload.error ?? payload.message) as string | undefined;
    if (message) return new CliError(`Server error (${status}): ${message}`, STATUS_HINTS[status]);
  }

  const snippet = bodyText.replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim().slice(0, 160);
  return new CliError(`Server error (${status})${snippet ? `: ${snippet}` : ""}`, STATUS_HINTS[status]);
}

async function apiPost(
  config: Config,
  path: string,
  parts: Part[],
  activity?: Activity,
): Promise<UploadResponse> {
  const { contentType, body } = buildMultipart(
    parts,
    activity ? (sent, total) => activity.progress(sent, total) : undefined,
  );

  const headers: Record<string, string> = {
    "Content-Type": contentType,
    "Accept": "application/json",
    "User-Agent": `shup-cli/${VERSION}`,
  };
  if (config.apiKey) headers["Authorization"] = config.apiKey;

  let response: Response;
  try {
    response = await fetch(config.url + path, {
      method: "POST",
      headers,
      body,
      duplex: "half",
    } as RequestInit);
  } catch (error) {
    throw new CliError(
      `Could not reach ${config.url}: ${(error as Error).message}`,
      "Is the server up, and is the URL in `shup config show` correct?",
    );
  }

  const text = await response.text();
  if (!response.ok) throw describeHttpError(response.status, text);
  if (!text.trim()) return { url: "", short_code: "" };

  try {
    return JSON.parse(text) as UploadResponse;
  } catch {
    throw new CliError(
      "The server did not return JSON.",
      "The configured URL may not point at a Shup instance.",
    );
  }
}

// ---------------------------------------------------------------------------
// Argument parsing
// ---------------------------------------------------------------------------

interface Options {
  text: boolean;
  fromFile: boolean;
  directory: boolean;
  short: boolean;
  password?: string;
  promptPassword: boolean;
  expires?: string;
  name?: string;
  description?: string;
  exclude: string[];
  copy: boolean;
  json: boolean;
  quiet: boolean;
  help: boolean;
  version: boolean;
  noColor: boolean;
  url?: string;
  apiKey?: string;
  positional: string[];
}

const SHORT_FLAGS: Record<string, keyof Options> = {
  t: "text",
  f: "fromFile",
  d: "directory",
  s: "short",
  c: "copy",
  j: "json",
  q: "quiet",
  h: "help",
  V: "version",
};

/** Short options that take a value. The value of `-p` is optional. */
const SHORT_VALUES: Record<string, keyof Options> = {
  p: "password",
  e: "expires",
  n: "name",
  x: "exclude",
};

function parseArgs(argv: string[]): Options {
  const options: Options = {
    text: false,
    fromFile: false,
    directory: false,
    short: false,
    promptPassword: false,
    exclude: [],
    copy: false,
    json: false,
    quiet: false,
    help: false,
    version: false,
    noColor: false,
    positional: [],
  };

  const assign = (key: keyof Options, value: unknown) => {
    if (key === "exclude") options.exclude.push(value as string);
    else (options as unknown as Record<string, unknown>)[key] = value;
  };

  let index = 0;
  while (index < argv.length) {
    const token = argv[index++];

    if (token === "--") {
      options.positional.push(...argv.slice(index));
      break;
    }

    if (token.startsWith("--")) {
      const equals = token.indexOf("=");
      const name = equals === -1 ? token.slice(2) : token.slice(2, equals);
      const inline = equals === -1 ? undefined : token.slice(equals + 1);
      const takeValue = () => {
        if (inline !== undefined) return inline;
        if (index < argv.length) return argv[index++];
        throw new CliError(`--${name} needs a value.`);
      };

      switch (name) {
        case "text": options.text = true; break;
        case "file": options.fromFile = true; break;
        case "dir":
        case "directory": options.directory = true; break;
        case "short":
        case "shorten": options.short = true; break;
        case "password":
          if (inline !== undefined) options.password = inline;
          else if (index < argv.length && !argv[index].startsWith("-")) options.password = argv[index++];
          else options.promptPassword = true;
          break;
        case "expire":
        case "expires": options.expires = takeValue(); break;
        case "name": options.name = takeValue(); break;
        case "desc":
        case "description": options.description = takeValue(); break;
        case "exclude": options.exclude.push(takeValue()); break;
        case "copy": options.copy = true; break;
        case "json": options.json = true; break;
        case "quiet": options.quiet = true; break;
        case "no-color": options.noColor = true; break;
        case "url": options.url = takeValue(); break;
        case "api-key": options.apiKey = takeValue(); break;
        case "help": options.help = true; break;
        case "version": options.version = true; break;
        default:
          throw new CliError(`Unknown option --${name}.`, "Run `shup --help` for the full list.");
      }
      continue;
    }

    // A lone "-" means stdin rather than a flag.
    if (token.length > 1 && token.startsWith("-")) {
      const cluster = token.slice(1);
      for (let position = 0; position < cluster.length; position++) {
        const letter = cluster[position];

        if (letter in SHORT_FLAGS) {
          assign(SHORT_FLAGS[letter], true);
          continue;
        }

        if (!(letter in SHORT_VALUES)) {
          throw new CliError(`Unknown option -${letter}.`, "Run `shup --help` for the full list.");
        }

        const key = SHORT_VALUES[letter];
        const rest = cluster.slice(position + 1);
        if (rest) {
          assign(key, rest);
        } else if (index < argv.length && (letter !== "p" || !argv[index].startsWith("-"))) {
          assign(key, argv[index++]);
        } else if (letter === "p") {
          options.promptPassword = true;
        } else {
          throw new CliError(`-${letter} needs a value.`);
        }
        break;
      }
      continue;
    }

    options.positional.push(token);
  }

  return options;
}

// ---------------------------------------------------------------------------
// Help
// ---------------------------------------------------------------------------

function helpText(): string {
  const h = (text: string) => c.bold(text);
  const f = (text: string) => c.cyan(text);
  return `
${c.bold(c.magenta("shup"))} ${c.gray(VERSION)} - upload files, text, directories and links

${h("USAGE")}
  ${f("shup")} [options] <file>...              Upload one or more files
  ${f("shup -t")} <text>                        Create a paste from text
  ${f("shup -tf")} <file>                       Create a paste from a file's contents
  ${f("shup -d")} <directory>                   Upload a directory, structure intact
  ${f("shup -s")} <url> [shortcode]             Shorten a URL
  ${f("shup config")} <init|show|set|path>      Manage the local configuration

${h("UPLOAD OPTIONS")}
  ${f("-p, --password")} [PASSWORD]  Protect the upload. Omit the value to be prompted.
  ${f("-e, --expires")} SPEC         Delete the upload after SPEC (see below).
  ${f("-n, --name")} NAME            Name for a directory, or filename when reading stdin.
      ${f("--description")} TEXT     Description for a directory.
  ${f("-x, --exclude")} GLOB         Skip matching paths in a directory upload (repeatable).

${h("OUTPUT OPTIONS")}
  ${f("-c, --copy")}                 Copy the resulting URL to the clipboard.
  ${f("-q, --quiet")}                Print only the URL.
  ${f("-j, --json")}                 Print the result as JSON.
      ${f("--no-color")}             Disable coloured output.

${h("GENERAL")}
      ${f("--url")} URL              Override the configured server URL.
      ${f("--api-key")} KEY          Override the configured API key.
  ${f("-h, --help")}                 Show this help.
  ${f("-V, --version")}              Show the version.

${h("EXPIRY FORMATS")} ${c.gray("(-e/--expires, minutes are the smallest unit)")}
  ${f("90")}                   90 minutes            ${f("2:30")}        2 hours 30 minutes
  ${f("1:12:30")}              1 day 12h 30m         ${f("2d12h")}       2 days 12 hours
  ${f("24-12-2026")}           midnight on that day
  ${f('"24-12-2026 18:30"')}   that date and time    ${c.gray("(quote it, it has a space)")}

${h("EXAMPLES")}
  ${c.gray("$")} shup screenshot.png
  ${c.gray("$")} shup -p hunter2 -e 1:00:00 secret.pdf
  ${c.gray("$")} shup -t "quick note to self"
  ${c.gray("$")} shup -tf ~/notes/todo.md -e 7d
  ${c.gray("$")} shup -d ./project -n "Project files" -x "*.log" -x node_modules
  ${c.gray("$")} shup -s https://example.com/a/very/long/link mylink
  ${c.gray("$")} cat report.csv | shup -n report.csv -
  ${c.gray("$")} git log | shup -t -c

${h("CONFIGURATION")}
  The first run asks for your server URL and API key, then saves them to
  ${CONFIG_FILE}
  Change them later with ${f("shup config init")} or ${f("shup config set")}.
  Environment: ${f("SHUP_URL")}, ${f("SHUP_API_KEY")}, ${f("SHUP_CONFIG")}, ${f("NO_COLOR")}
`;
}

// ---------------------------------------------------------------------------
// Filesystem helpers
// ---------------------------------------------------------------------------

function basename(path: string): string {
  const trimmed = path.replace(/[\\/]+$/, "");
  const index = Math.max(trimmed.lastIndexOf("/"), trimmed.lastIndexOf("\\"));
  return index === -1 ? trimmed : trimmed.slice(index + 1);
}

function joinPath(...segments: string[]): string {
  return segments.filter(Boolean).join("/");
}

/**
 * A hint for the multipart part header. The server detects the real type from
 * the file contents, so unknown extensions can safely fall back.
 */
const MIME_TYPES: Record<string, string> = {
  aac: "audio/aac",
  avi: "video/x-msvideo",
  avif: "image/avif",
  bmp: "image/bmp",
  css: "text/css",
  csv: "text/csv",
  flac: "audio/flac",
  gif: "image/gif",
  gz: "application/gzip",
  htm: "text/html",
  html: "text/html",
  ico: "image/vnd.microsoft.icon",
  jpeg: "image/jpeg",
  jpg: "image/jpeg",
  js: "text/javascript",
  json: "application/json",
  m4a: "audio/mp4",
  md: "text/markdown",
  mkv: "video/x-matroska",
  mov: "video/quicktime",
  mp3: "audio/mpeg",
  mp4: "video/mp4",
  odp: "application/vnd.oasis.opendocument.presentation",
  ods: "application/vnd.oasis.opendocument.spreadsheet",
  odt: "application/vnd.oasis.opendocument.text",
  ogg: "audio/ogg",
  opus: "audio/opus",
  pdf: "application/pdf",
  png: "image/png",
  rar: "application/vnd.rar",
  rs: "text/x-rust",
  svg: "image/svg+xml",
  tar: "application/x-tar",
  toml: "application/toml",
  ts: "text/typescript",
  txt: "text/plain",
  wasm: "application/wasm",
  wav: "audio/wav",
  webm: "video/webm",
  webp: "image/webp",
  xml: "application/xml",
  yaml: "application/yaml",
  yml: "application/yaml",
  zip: "application/zip",
  "7z": "application/x-7z-compressed",
};

function guessMime(filename: string): string {
  const dot = filename.lastIndexOf(".");
  if (dot === -1) return "application/octet-stream";
  return MIME_TYPES[filename.slice(dot + 1).toLowerCase()] ?? "application/octet-stream";
}

function statFile(path: string): Deno.FileInfo {
  try {
    return Deno.statSync(path);
  } catch (error) {
    if (error instanceof Deno.errors.NotFound) throw new CliError(`No such file or directory: ${path}`);
    if (error instanceof Deno.errors.PermissionDenied) throw new CliError(`Permission denied: ${path}`);
    throw new CliError(`Cannot read ${path}: ${(error as Error).message}`);
  }
}

/** Minimal glob matcher supporting `*`, `**` and `?`. */
function globToRegExp(glob: string): RegExp {
  let pattern = "";
  for (let index = 0; index < glob.length; index++) {
    const char = glob[index];
    if (char === "*" && glob[index + 1] === "*") {
      pattern += ".*";
      index++;
      if (glob[index + 1] === "/") index++;
    } else if (char === "*") {
      pattern += "[^/]*";
    } else if (char === "?") {
      pattern += "[^/]";
    } else {
      pattern += char.replace(/[.+^${}()|[\]\\]/g, "\\$&");
    }
  }
  return new RegExp(`^${pattern}$`);
}

/** Matches a relative path against the patterns, path segments included. */
function makeExcluder(globs: string[]): (relativePath: string) => boolean {
  if (!globs.length) return () => false;
  const patterns = globs.map(globToRegExp);
  return (relativePath: string) =>
    patterns.some((pattern) =>
      pattern.test(relativePath) ||
      relativePath.split("/").some((segment) => pattern.test(segment))
    );
}

async function readStdin(): Promise<Uint8Array> {
  const chunks: Uint8Array[] = [];
  let total = 0;
  for await (const chunk of Deno.stdin.readable) {
    chunks.push(chunk);
    total += chunk.byteLength;
  }
  const buffer = new Uint8Array(total);
  let offset = 0;
  for (const chunk of chunks) {
    buffer.set(chunk, offset);
    offset += chunk.byteLength;
  }
  return buffer;
}

const CLIPBOARD_COMMANDS = [
  ["wl-copy"],
  ["xclip", "-selection", "clipboard"],
  ["xsel", "--clipboard", "--input"],
  ["pbcopy"],
  ["clip.exe"],
];

async function copyToClipboard(text: string): Promise<boolean> {
  for (const [command, ...args] of CLIPBOARD_COMMANDS) {
    try {
      const child = new Deno.Command(command, {
        args,
        stdin: "piped",
        stdout: "null",
        stderr: "null",
      }).spawn();
      const writer = child.stdin.getWriter();
      await writer.write(encoder.encode(text));
      await writer.close();
      if ((await child.status).success) return true;
    } catch {
      // Tool is unavailable, try the next one.
    }
  }
  return false;
}

// ---------------------------------------------------------------------------
// Results
// ---------------------------------------------------------------------------

interface Result {
  kind: "file" | "paste" | "directory" | "link";
  label: string;
  url: string;
  shortCode: string;
  size?: number;
  expiresIn?: number;
  files?: number;
  folders?: number;
}

const KIND_ICONS: Record<Result["kind"], string> = {
  file: "📄",
  paste: "📝",
  directory: "📁",
  link: "🔗",
};

function report(results: Result[], options: Options) {
  if (options.json) {
    writeOut(JSON.stringify(results.length === 1 ? results[0] : results, null, 2) + "\n");
    return;
  }

  if (options.quiet) {
    for (const result of results) writeOut(result.url + "\n");
    return;
  }

  if (results.length === 1) {
    const [result] = results;
    const meta: string[] = [];
    if (result.files !== undefined) meta.push(`${result.files} files`);
    if (result.size !== undefined) meta.push(formatBytes(result.size));
    if (result.expiresIn !== undefined) meta.push(`expires in ${formatDuration(result.expiresIn)}`);

    writeErr(`\n  ${KIND_ICONS[result.kind]} ${c.bold(result.label)}`);
    writeErr(meta.length ? c.gray(`  ${meta.join(" · ")}\n`) : "\n");
    writeOut(`  ${c.green(result.url)}\n`);
    writeErr("\n");
    return;
  }

  const width = Math.max(...results.map((result) => result.label.length));
  const total = results.reduce((sum, result) => sum + (result.size ?? 0), 0);
  writeErr(`\n  ${c.green("✓")} ${c.bold(`${results.length} uploads`)} ${c.gray(`· ${formatBytes(total)}`)}\n\n`);
  for (const result of results) {
    writeOut(`  ${result.label.padEnd(width)}  ${c.green(result.url)}\n`);
  }
  writeErr("\n");
}

// ---------------------------------------------------------------------------
// Uploads
// ---------------------------------------------------------------------------

interface Session {
  config: Config;
  password?: string;
  /** Minutes until the upload expires, or undefined to keep it indefinitely. */
  expires?: number;
}

function sharedParts(session: Session): Part[] {
  const parts: Part[] = [];
  if (session.password) parts.push({ kind: "field", name: "password", value: session.password });
  if (session.expires) parts.push({ kind: "field", name: "expires", value: String(session.expires) });
  return parts;
}

async function uploadFile(part: Part & { kind: "file" | "bytes" }, size: number, session: Session): Promise<Result> {
  const activity = new Activity(`Uploading ${c.bold(part.filename)} ${c.gray(formatBytes(size))}`);
  try {
    const response = await apiPost(session.config, "/f", [part, ...sharedParts(session)], activity);
    return {
      kind: "file",
      label: part.filename,
      url: response.url,
      shortCode: response.short_code,
      size,
      expiresIn: session.expires,
    };
  } finally {
    activity.stop();
  }
}

async function uploadFiles(paths: string[], session: Session, options: Options): Promise<Result[]> {
  const results: Result[] = [];

  for (const path of paths) {
    if (path === "-") {
      const data = await readStdin();
      if (!data.byteLength) throw new CliError("Nothing was piped into stdin.");
      const filename = options.name ?? "stdin";
      results.push(await uploadFile(
        { kind: "bytes", name: "file", filename, data, mime: guessMime(filename) },
        data.byteLength,
        session,
      ));
      continue;
    }

    const stat = statFile(path);
    if (stat.isDirectory) {
      throw new CliError(`${path} is a directory.`, `Use \`shup -d ${path}\` to upload it as a directory.`);
    }

    const filename = basename(path);
    results.push(await uploadFile(
      { kind: "file", name: "file", filename, path, size: stat.size, mime: guessMime(filename) },
      stat.size,
      session,
    ));
  }

  return results;
}

async function uploadPaste(content: string, label: string, session: Session): Promise<Result> {
  const size = encoder.encode(content).byteLength;
  const activity = new Activity(`Creating paste ${c.gray(formatBytes(size))}`);
  try {
    const response = await apiPost(session.config, "/p", [
      { kind: "field", name: "content", value: content },
      ...sharedParts(session),
    ], activity);
    return {
      kind: "paste",
      label,
      url: response.url,
      shortCode: response.short_code,
      size,
      expiresIn: session.expires,
    };
  } finally {
    activity.stop();
  }
}

async function shortenUrl(target: string, code: string | undefined, session: Session): Promise<Result> {
  if (!/^https?:\/\//i.test(target)) {
    throw new CliError(`"${target}" is not a valid URL.`, "Include the scheme, for example https://example.com");
  }
  if (code && !/^[a-zA-Z0-9_-]{1,20}$/.test(code)) {
    throw new CliError(
      `"${code}" is not a valid shortcode.`,
      "Use 1 to 20 characters from a-z, A-Z, 0-9, - and _.",
    );
  }

  const activity = new Activity(`Shortening ${c.gray(target)}`);
  try {
    const parts: Part[] = [
      { kind: "field", name: "url", value: target },
      ...sharedParts(session),
    ];
    if (code) parts.push({ kind: "field", name: "custom_url", value: code });

    const response = await apiPost(session.config, "/s", parts, activity);
    return {
      kind: "link",
      label: target,
      url: response.url,
      shortCode: response.short_code,
      expiresIn: session.expires,
    };
  } finally {
    activity.stop();
  }
}

// ---------------------------------------------------------------------------
// Directory uploads
// ---------------------------------------------------------------------------

interface WalkedFile {
  path: string;
  relative: string;
  size: number;
}

interface Walk {
  files: WalkedFile[];
  folders: string[];
  skipped: number;
}

async function walkDirectory(root: string, exclude: (relative: string) => boolean): Promise<Walk> {
  const files: WalkedFile[] = [];
  const folders: string[] = [];
  let skipped = 0;

  const visit = async (absolute: string, relative: string) => {
    for await (const entry of Deno.readDir(absolute)) {
      const childRelative = joinPath(relative, entry.name);
      const childAbsolute = joinPath(absolute, entry.name);

      if (entry.isSymlink || exclude(childRelative)) {
        skipped++;
        continue;
      }

      if (entry.isDirectory) {
        folders.push(childRelative);
        await visit(childAbsolute, childRelative);
      } else if (entry.isFile) {
        const stat = await Deno.stat(childAbsolute);
        files.push({ path: childAbsolute, relative: childRelative, size: stat.size });
      }
    }
  };

  await visit(root.replace(/\/+$/, ""), "");
  return { files, folders, skipped };
}

/** PHP's `max_file_uploads` defaults to 20, and files beyond it are dropped. */
const BATCH_FILES = 20;
const BATCH_BYTES = 24 * 1024 * 1024;

/** Splits the upload into requests that stay under the server's POST limits. */
function batchFiles(files: WalkedFile[]): WalkedFile[][] {
  const batches: WalkedFile[][] = [];
  let current: WalkedFile[] = [];
  let bytes = 0;

  for (const file of files) {
    if (current.length && (current.length >= BATCH_FILES || bytes + file.size > BATCH_BYTES)) {
      batches.push(current);
      current = [];
      bytes = 0;
    }
    current.push(file);
    bytes += file.size;
  }
  if (current.length) batches.push(current);

  return batches;
}

/** Folders that hold no files anywhere below them, which no uploaded path implies. */
function emptyFolders(walk: Walk): string[] {
  const implied = new Set<string>();
  for (const file of walk.files) {
    let current = "";
    for (const segment of file.relative.split("/").slice(0, -1)) {
      current = joinPath(current, segment);
      implied.add(current);
    }
  }
  return walk.folders.filter((folder) => !implied.has(folder));
}

async function uploadDirectory(path: string, session: Session, options: Options): Promise<Result> {
  const stat = statFile(path);
  if (!stat.isDirectory) {
    throw new CliError(`${path} is not a directory.`, "Drop the -d flag to upload it as a file.");
  }
  if (!session.config.apiKey) {
    throw new CliError("Directory uploads require an API key.", "Run `shup config init` to store one.");
  }

  const scan = new Activity(`Scanning ${c.bold(path)}`);
  const walk = await walkDirectory(path, makeExcluder(options.exclude));
  scan.stop();

  if (!walk.files.length && !walk.folders.length) {
    throw new CliError(`${path} is empty, or everything in it was excluded.`);
  }

  const totalBytes = walk.files.reduce((sum, file) => sum + file.size, 0);
  const name = (options.name ?? basename(path) ?? "directory").slice(0, 120);
  info(`  ${c.gray(
    `${walk.files.length} file(s), ${walk.folders.length} folder(s), ${formatBytes(totalBytes)}` +
      (walk.skipped ? `, ${walk.skipped} skipped` : ""),
  )}`);

  const create = new Activity(`Creating directory ${c.bold(name)}`);
  let directory: UploadResponse;
  try {
    const parts: Part[] = [{ kind: "field", name: "name", value: name }, ...sharedParts(session)];
    if (options.description) {
      parts.push({ kind: "field", name: "description", value: options.description });
    }
    directory = await apiPost(session.config, "/d", parts, create);
  } finally {
    create.stop();
  }

  const batches = batchFiles(walk.files);
  let uploaded = 0;

  for (const [index, batch] of batches.entries()) {
    const label = batches.length > 1
      ? `Uploading batch ${index + 1}/${batches.length} ${c.gray(`(${uploaded}/${walk.files.length} files)`)}`
      : `Uploading ${walk.files.length} file(s)`;
    const activity = new Activity(label);

    const parts: Part[] = batch.map((file) => ({
      kind: "file",
      name: "files[]",
      filename: basename(file.relative),
      path: file.path,
      size: file.size,
      mime: guessMime(file.relative),
    }));
    for (const file of batch) {
      parts.push({ kind: "field", name: "paths[]", value: file.relative });
    }

    try {
      const response = await apiPost(session.config, `/d/${directory.short_code}/-/upload`, parts, activity);
      if (response.uploaded !== undefined && response.uploaded < batch.length) {
        throw new CliError(
          `The server stored only ${response.uploaded} of ${batch.length} files in this batch.`,
          "Its max_file_uploads limit is lower than expected.",
        );
      }
      uploaded += batch.length;
    } catch (error) {
      activity.stop();
      throw new CliError(
        `Directory upload failed after ${uploaded}/${walk.files.length} file(s): ${(error as Error).message}`,
        `The partially uploaded directory is at ${directory.url}`,
      );
    } finally {
      activity.stop();
    }
  }

  const empty = emptyFolders(walk);
  if (empty.length) {
    const activity = new Activity(`Creating ${empty.length} empty folder(s)`);
    try {
      for (const folder of empty) {
        await apiPost(session.config, `/d/${directory.short_code}/-/folders`, [
          { kind: "field", name: "name", value: folder },
        ]);
      }
    } catch {
      warn("Could not create every empty folder. The files themselves were uploaded.");
    } finally {
      activity.stop();
    }
  }

  return {
    kind: "directory",
    label: name,
    url: directory.url,
    shortCode: directory.short_code,
    size: totalBytes,
    expiresIn: session.expires,
    files: uploaded,
    folders: walk.folders.length,
  };
}

// ---------------------------------------------------------------------------
// Setup and config subcommand
// ---------------------------------------------------------------------------

function maskKey(key: string | undefined): string {
  if (!key) return c.gray("(not set)");
  if (key.length <= 8) return "•".repeat(key.length);
  return key.slice(0, 4) + "•".repeat(key.length - 8) + key.slice(-4);
}

async function serverIsReachable(url: string): Promise<boolean> {
  try {
    return (await fetch(url + "/up")).ok;
  } catch {
    return false;
  }
}

async function promptLine(message: string, fallback = ""): Promise<string> {
  writeErr(message + (fallback ? ` ${c.gray(`[${fallback}]`)}` : "") + " ");
  const buffer = new Uint8Array(1024);
  const read = await Deno.stdin.read(buffer);
  const value = read === null ? "" : decoder.decode(buffer.subarray(0, read)).trim();
  return value || fallback;
}

/** Asks for a server and an API key, then writes them to the config file. */
async function runSetup(existing: Partial<Config>): Promise<Config> {
  writeErr(`\n  ${c.bold("Shup setup")} ${c.gray(CONFIG_FILE)}\n\n`);

  const answer = await promptLine("  Server URL:", existing.url ?? "");
  if (!answer) throw new CliError("A server URL is required.");

  let url = normalizeUrl(answer);
  let reachable = await serverIsReachable(url);

  // Without a scheme the URL was assumed to be https, which local and LAN
  // instances often do not serve. Fall back only if plain http answers.
  if (!reachable && !/^https?:\/\//i.test(answer)) {
    const insecure = url.replace(/^https:/, "http:");
    reachable = await serverIsReachable(insecure);
    if (reachable) url = insecure;
  }

  if (reachable) writeErr(`  ${c.green("✓")} ${url} is reachable\n`);
  else warn(`Could not reach ${url}/up, saving anyway.`);

  writeErr(`\n  ${c.gray("Find your API key on the Shup profile page.")}\n`);
  writeErr(`  ${c.gray("Leave it blank to upload anonymously, if the server allows that.")}\n`);
  const apiKey = await promptSecret("  API key: ");

  const config: Config = { url, apiKey: apiKey || existing.apiKey || "" };
  writeConfigFile(config);
  writeErr(`\n  ${c.green("✓")} Saved to ${c.gray(CONFIG_FILE)}\n\n`);

  return config;
}

async function configCommand(args: string[]): Promise<number> {
  const action = args[0] ?? "show";
  const file = readConfigFile();

  switch (action) {
    case "path":
      writeOut(CONFIG_FILE + "\n");
      return 0;

    case "show":
      writeErr(`\n  ${c.bold("Configuration")} ${c.gray(CONFIG_FILE)}\n\n`);
      writeErr(`    url      ${file.url ? c.green(file.url) : c.gray("(not set)")}\n`);
      writeErr(`    apiKey   ${maskKey(file.apiKey)}\n`);
      if (env("SHUP_URL")) writeErr(`\n    ${c.yellow("SHUP_URL")} overrides url with ${env("SHUP_URL")}\n`);
      if (env("SHUP_API_KEY")) writeErr(`    ${c.yellow("SHUP_API_KEY")} overrides apiKey\n`);
      writeErr("\n");
      return 0;

    case "set": {
      const [, key, value] = args;
      if (!key || value === undefined) throw new CliError("Usage: shup config set <url|apiKey> <value>");

      if (key === "url") file.url = normalizeUrl(value);
      else if (key === "apiKey" || key === "key") file.apiKey = value;
      else throw new CliError(`Unknown setting "${key}".`, "Valid settings: url, apiKey");

      writeConfigFile(file);
      info(`  ${c.green("✓")} Saved to ${c.gray(CONFIG_FILE)}`);
      return 0;
    }

    case "init":
      await runSetup(file);
      return 0;

    default:
      throw new CliError(`Unknown config action "${action}".`, "Use init, show, set or path.");
  }
}

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

async function readTextArgument(path: string): Promise<string> {
  if (path === "-") return decoder.decode(await readStdin());
  statFile(path);
  return Deno.readTextFileSync(path);
}

async function run(argv: string[]): Promise<number> {
  if (argv[0] === "config") return await configCommand(argv.slice(1));

  const options = parseArgs(argv);
  if (options.noColor) term.color = false;
  term.quiet = options.quiet;

  if (options.version) {
    writeOut(`shup ${VERSION}\n`);
    return 0;
  }
  if (options.help || (!argv.length && Deno.stdin.isTerminal())) {
    writeOut(helpText());
    return options.help ? 0 : 1;
  }

  if ([options.text, options.directory, options.short].filter(Boolean).length > 1) {
    throw new CliError("Choose one of -t (text), -d (directory) or -s (short link).");
  }

  // Parsed up front so a bad value fails before anything prompts for input.
  const expires = validateExpiry(options.expires ? parseExpiry(options.expires) : undefined);

  const session: Session = {
    config: await resolveConfig({ url: options.url, apiKey: options.apiKey }),
    password: (options.promptPassword ? await promptSecret("  Password: ") : options.password) || undefined,
    expires,
  };

  if (options.short && session.password) {
    warn("Short links cannot be password protected, ignoring -p.");
    session.password = undefined;
  }

  let results: Result[];

  if (options.short) {
    const [target, code, ...rest] = options.positional;
    if (!target) throw new CliError("-s needs a URL.", "Example: shup -s https://example.com mylink");
    if (rest.length) throw new CliError("-s takes at most a URL and a shortcode.");
    results = [await shortenUrl(target, code, session)];
  } else if (options.text && options.fromFile) {
    const [path, ...rest] = options.positional;
    if (!path) throw new CliError("-tf needs a file to read.");
    if (rest.length) throw new CliError("-tf takes a single file.");

    const content = await readTextArgument(path);
    if (!content) throw new CliError(`${path} is empty.`);
    results = [await uploadPaste(content, options.name ?? basename(path), session)];
  } else if (options.text) {
    const content = options.positional.join(" ") || decoder.decode(await readStdin());
    if (!content.trim()) {
      throw new CliError("No text to upload.", 'Pass it inline (shup -t "hello") or pipe it in.');
    }
    results = [await uploadPaste(content, options.name ?? "paste", session)];
  } else if (options.directory) {
    if (!options.positional.length) throw new CliError("-d needs a directory.");
    results = [];
    for (const path of options.positional) {
      results.push(await uploadDirectory(path, session, options));
    }
  } else {
    if (!options.positional.length) throw new CliError("Nothing to upload.", "Run `shup --help` for usage.");
    results = await uploadFiles(options.positional, session, options);
  }

  report(results, options);

  if (options.copy) {
    const copied = await copyToClipboard(results.map((result) => result.url).join("\n"));
    if (copied) info(`  ${c.green("✓")} Copied to clipboard`);
    else warn("No clipboard tool found (tried wl-copy, xclip, xsel, pbcopy, clip.exe).");
  }

  return 0;
}

if (import.meta.main) {
  try {
    Deno.exit(await run(Deno.args));
  } catch (error) {
    if (!(error instanceof CliError)) throw error;
    writeErr(`\n  ${c.red("✗")} ${error.message}\n`);
    if (error.hint) writeErr(`    ${c.gray(error.hint)}\n`);
    writeErr("\n");
    Deno.exit(1);
  }
}
