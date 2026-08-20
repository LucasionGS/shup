<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesApiUser;
use App\Jobs\GenerateThumbnail;
use App\Models\File;
use App\Models\User;
use App\Support\PasswordCrypto;
use App\Support\Thumbnailer;
use Auth;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    use ResolvesApiUser;

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $shortCode, ?string $filename = null)
    {
        /** @var File */
        $file = File::where('short_code', $shortCode)
            ->where(function ($query) {
                $query->whereNull('expires')->orWhere('expires', '>', now());
            })
            ->firstOrFail();

        // Answered before the filename redirect, so a listing full of previews
        // does not pay for a redirect per image. Only unprotected images
        // qualify: a derivative of an encrypted file would leak its contents.
        if ($request->boolean('thumb') && !$file->password) {
            if ($thumbnail = $this->thumbnailResponse($file)) {
                return $thumbnail;
            }
        }

        if ($filename) {
            if ($filename !== $file->original_name) {
                return response()->json([
                    'error' => 'File not found'
                ], 404);
            }
        }
        else {
            // Carry the query string across, otherwise ?password= is lost here
            // and a protected file can never be fetched from its short URL.
            $query = $request->getQueryString();

            return redirect(
                "/f/$shortCode/" . rawurlencode($file->original_name) . ($query ? "?$query" : '')
            );
        }

        $password = $request->input('password') ?? $request->input("pwd") ?? null;

        if ($file->password) {
            if (!$password) {
                return view('file-password');
            }

            if (!Hash::check($password, $file->password)) {
                return view('file-password');
            }
        }

        // A share page for humans, rather than an immediate download. Opt-in
        // via ?view=1 so existing links, ShareX embeds and the CLI keep
        // behaving exactly as before. Rendered before the download counter so
        // looking at a share does not consume one of its permitted downloads.
        if ($request->boolean('view')) {
            return view('file-preview', [
                'file' => $file,
                'password' => $file->password ? $password : null,
                'kind' => $this->previewKind($file),
                'textPreview' => $this->textPreview($file, $password),
            ]);
        }

        if ($file->hasReachedDownloadLimit()) {
            return response()->json([
                'error' => 'This file has reached its download limit.'
            ], 410);
        }

        if (!Auth::check() || Auth::id() !== $file->user_id) {
            $file->increment('downloads');
            $file->refresh();

            // Retire the share as soon as the final permitted download is served.
            if ($file->hasReachedDownloadLimit()) {
                $file->forceFill(['expires' => now()])->save();
            }
        }

        $path = $file->absolutePath();

        if (!is_file($path)) {
            abort(404);
        }

        // Inline playback for the preview page, restricted to media types that
        // cannot execute script. Everything else stays an attachment.
        $inline = $request->boolean('inline') && $this->isInlineSafe($file->mime);

        if ($file->password) {
            return $this->streamDecrypted($file, $path, $password, $inline);
        }

        $response = new BinaryFileResponse($path, 200, $this->downloadHeaders($file));
        $response->setContentDisposition(
            $inline
                ? ResponseHeaderBag::DISPOSITION_INLINE
                : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $file->original_name,
            $this->fallbackFilename($file->original_name)
        );
        // Content is immutable for a given short code, so it is safe to cache
        // hard; the ETag lets browsers revalidate cheaply.
        $response->setAutoEtag();
        $response->setPrivate();
        $response->setMaxAge(31536000);
        $response->headers->addCacheControlDirective('immutable');

        return $response;
    }

    /**
     * Serve a cached thumbnail, generating it on demand if the queued job has
     * not produced one yet. Returns null when this file cannot have one, so the
     * caller falls back to the full download.
     */
    private function thumbnailResponse(File $file): ?BinaryFileResponse
    {
        if (!Thumbnailer::supports($file->mime)) {
            return null;
        }

        if (!Thumbnailer::exists($file->short_code)) {
            $source = $file->absolutePath();

            if (!is_file($source)) {
                return null;
            }

            if (!Thumbnailer::generate($source, $file->short_code, $file->mime)) {
                return null;
            }
        }

        $response = new BinaryFileResponse(
            Thumbnailer::absolutePathFor($file->short_code),
            200,
            ['X-Content-Type-Options' => 'nosniff']
        );

        // Inline on purpose: this is a re-encoded derivative produced by the
        // server, not the bytes the user uploaded.
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'thumbnail.webp');
        $response->setAutoEtag();
        $response->setPrivate();
        $response->setMaxAge(31536000);
        $response->headers->addCacheControlDirective('immutable');

        return $response;
    }

    /**
     * Decrypt and stream a protected file in chunks.
     *
     * The previous implementation read the whole file into memory, decrypted it
     * into a second full copy, and handed that to response(), so peak memory was
     * several times the file size and large protected files exhausted the
     * worker. Streaming keeps memory flat regardless of file size.
     */
    private function streamDecrypted(File $file, string $path, string $password, bool $inline = false): StreamedResponse
    {
        $headers = $this->downloadHeaders($file);
        $headers['Content-Disposition'] = $inline
            ? $this->inlineDisposition($file->original_name)
            : $this->attachmentDisposition($file->original_name);

        return response()->stream(function () use ($path, $password) {
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                return;
            }

            try {
                PasswordCrypto::decryptStream($handle, fopen('php://output', 'wb'), $password);
            } finally {
                fclose($handle);
            }
        }, 200, $headers);
    }

    /**
     * Types that may be sent with an inline disposition.
     *
     * Deliberately an allowlist, and deliberately without SVG or anything
     * text/html-like: those are scriptable documents, and serving one inline
     * from this origin is stored XSS.
     */
    private function isInlineSafe(?string $mime): bool
    {
        $mime = strtolower((string) $mime);

        if ($mime === '' || str_contains($mime, 'svg') || str_contains($mime, 'html')) {
            return false;
        }

        return str_starts_with($mime, 'image/')
            || str_starts_with($mime, 'video/')
            || str_starts_with($mime, 'audio/')
            || $mime === 'application/pdf';
    }

    /**
     * Extensions rendered as text regardless of the sniffed type.
     *
     * Content sniffing reports any file containing markup as text/html, which
     * would otherwise leave an ordinary .txt or .md unpreviewable. This is safe
     * because the text preview is read server-side and escaped by Blade; the
     * raw bytes are never served inline (see isInlineSafe).
     */
    private const TEXT_EXTENSIONS = [
        'txt', 'md', 'markdown', 'log', 'csv', 'tsv', 'json', 'xml', 'yml', 'yaml',
        'ini', 'conf', 'cfg', 'env', 'sql', 'sh', 'bash', 'zsh', 'fish',
        'php', 'js', 'ts', 'jsx', 'tsx', 'css', 'scss', 'py', 'rb', 'go', 'rs',
        'java', 'c', 'h', 'cpp', 'hpp', 'cs', 'toml', 'lock', 'gitignore',
    ];

    /**
     * How the preview page should render this file.
     */
    private function previewKind(File $file): string
    {
        $mime = strtolower((string) $file->mime);
        $extension = strtolower((string) pathinfo($file->original_name, PATHINFO_EXTENSION));

        if (in_array($extension, self::TEXT_EXTENSIONS, true)) {
            return 'text';
        }

        if (str_contains($mime, 'svg') || str_contains($mime, 'html')) {
            return 'other';
        }

        if (str_starts_with($mime, 'image/')) return 'image';
        if (str_starts_with($mime, 'video/')) return 'video';
        if (str_starts_with($mime, 'audio/')) return 'audio';
        if ($mime === 'application/pdf') return 'pdf';

        if (str_starts_with($mime, 'text/') || in_array($mime, ['application/json', 'application/xml'], true)) {
            return 'text';
        }

        return 'other';
    }

    /** Largest text file rendered inline on the preview page. */
    private const TEXT_PREVIEW_BYTES = 131072;

    /**
     * Read a short text file for display. Returns null when the file is not
     * text, is too large, or cannot be decrypted.
     */
    private function textPreview(File $file, ?string $password): ?string
    {
        if ($this->previewKind($file) !== 'text') {
            return null;
        }

        $path = $file->absolutePath();

        if (!is_file($path)) {
            return null;
        }

        if ($file->password) {
            if (!$password || filesize($path) > self::TEXT_PREVIEW_BYTES * 2) {
                return null;
            }

            $out = fopen('php://temp', 'r+b');
            $in = fopen($path, 'rb');

            if ($in === false || $out === false) {
                return null;
            }

            PasswordCrypto::decryptStream($in, $out, $password);
            fclose($in);
            rewind($out);
            $contents = stream_get_contents($out, self::TEXT_PREVIEW_BYTES);
            fclose($out);

            return $contents === false ? null : $contents;
        }

        if (filesize($path) > self::TEXT_PREVIEW_BYTES) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /**
     * Headers shared by every download response.
     *
     * Files are served as attachments with sniffing disabled so an uploaded
     * .html or .svg cannot execute script on this origin.
     */
    private function downloadHeaders(File $file): array
    {
        return [
            'Content-Type' => $file->mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    private function attachmentDisposition(string $name): string
    {
        return (new ResponseHeaderBag())->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $name,
            $this->fallbackFilename($name)
        );
    }

    private function inlineDisposition(string $name): string
    {
        return (new ResponseHeaderBag())->makeDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $name,
            $this->fallbackFilename($name)
        );
    }

    /**
     * ASCII-only fallback for user agents that cannot handle RFC 5987 encoding.
     */
    private function fallbackFilename(string $name): string
    {
        $fallback = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'download';
        $fallback = str_replace(['"', '\\', '%'], '_', $fallback);

        return trim($fallback) !== '' ? $fallback : 'download';
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:' . $this->maxUploadKilobytes()],
            'password' => 'nullable|string',
            'expires' => 'nullable|integer|min:0',
            'max_downloads' => 'nullable|integer|min:1',
        ]);

        /** @var User|null */
        $uploader = $this->resolveApiUser($request);

        if ($authRes = $this->rejectIfNotAuthenticatedIfNeeded($uploader)) { return $authRes; }

        $uploadedFile = $request->file('file');
        /** @var string|null */
        $password = $request->input('password') ?? $request->input("pwd") ?? null;

        if ($quotaRes = $this->rejectIfOverQuota($uploader, $uploadedFile->getSize() ?? 0)) {
            return $quotaRes;
        }

        /** @var int|null */
        $expiresMins = $request->input('expires', null);

        $fileName = $uploadedFile->getClientOriginalName();
        $ext = $uploadedFile->guessExtension();
        $mime = $uploadedFile->getMimeType();

        // Retry until the code is free: without this a collision overwrote the
        // existing owner's bytes on disk before the unique constraint failed.
        do {
            $shortCode = $this->generateShortcode();
        } while (File::where('short_code', $shortCode)->exists());

        $uploadedFile->storeAs("files", $password ? "__$shortCode" : $shortCode);

        if ($expiresMins && $expiresMins < 0) {
            $expiresMins = 0;
        }

        if ($uploader) {
            $expireDate = $expiresMins ? now()->addMinutes((int)$expiresMins) : null;
        }
        else {
            // If no user is logged in, the file will be deleted in 7 days. Cannot be set over 7 days.
            if ($expiresMins > 10080) {
                $expireDate = now()->addDays(7);
            }
            else {
                $expireDate = $expiresMins ? now()->addMinutes((int)$expiresMins) : now()->addDays(7);
            }
        }

        $directory = File::storageDirectory();

        // ENCRYPTION
        if ($password) {
            PasswordCrypto::encryptFile("$directory/__$shortCode", "$directory/$shortCode", $password);
            unlink("$directory/__$shortCode");
        }
        // /ENCRYPTION

        $url = url("/f/$shortCode");

        $filesize = filesize("$directory/$shortCode");
        File::create([
            'short_code' => $shortCode,
            'original_name' => $fileName,
            'ext' => $ext,
            'mime' => $mime,
            'password' => $password ? Hash::make($password) : null,
            'user_id' => $uploader?->id,
            'expires' => $expireDate,
            'size' => $filesize,
            'max_downloads' => $request->input('max_downloads') ?: null,
        ]);

        if ($uploader) {
            $uploader->increment('storage_used', $filesize);
        }

        // Unprotected images get a thumbnail built off the request path.
        if (!$password && Thumbnailer::supports($mime)) {
            GenerateThumbnail::dispatch("$directory/$shortCode", $shortCode, $mime);
        }

        if ($request->query("_back")) { return back()->with("short_url", $url); }

        return response()->json([
            'url' => $url,
            'short_code' => $shortCode
        ], 201);
    }

    public function destroy(Request $request, string $shortCode)
    {
        /** @var File|null */
        $file = File::where('short_code', $shortCode)->first();

        if (!$file) {
            return response()->json([
                'error' => 'File not found'
            ], 404);
        }

        $user = $this->resolveApiUser($request);
        $password = $request->input('password') ?? $request->input("pwd") ?? null;
        $isOwner = $user && $file->user_id && $user->id === $file->user_id;

        // Previously an unprotected file fell through every branch here and was
        // deleted, so anyone who knew a short code could destroy another user's
        // upload. Deletion now requires ownership, or the file's password.
        if (!$isOwner) {
            if (!$file->password) {
                return response()->json([
                    'error' => 'Unauthorized'
                ], 403);
            }

            if (!$password || !Hash::check($password, $file->password)) {
                return response()->json([
                    'error' => 'Invalid password'
                ], 403);
            }
        }

        $file->expire();

        if ($request->query("_back")) { return back(); }

        return response()->json([
            'message' => 'File deleted'
        ], 204);
    }
}
