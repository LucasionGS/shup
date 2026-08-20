<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesApiUser;
use App\Jobs\GenerateThumbnail;
use App\Models\File;
use App\Models\UploadSession;
use App\Models\User;
use App\Support\PasswordCrypto;
use App\Support\Thumbnailer;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Resumable uploads.
 *
 * A direct upload has to fit inside upload_max_filesize, post_max_size and
 * max_execution_time simultaneously, and a dropped connection at 90% of a large
 * file means starting over. Here the client opens a session, sends the file in
 * chunks, and can ask at any time how many bytes arrived so it can resume from
 * that offset.
 *
 *   POST /f/chunk            open a session
 *   GET  /f/chunk/{token}    how much has landed (resume point)
 *   POST /f/chunk/{token}    append the next chunk
 *   POST /f/chunk/{token}/complete   promote the upload into a file
 */
class ChunkedUploadController extends Controller
{
    use ResolvesApiUser;

    public function create(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'size' => 'required|integer|min:1',
            'mime' => 'nullable|string|max:255',
            'password' => 'nullable|string',
            'expires' => 'nullable|integer|min:0',
            'max_downloads' => 'nullable|integer|min:1',
        ]);

        /** @var User|null */
        $uploader = $this->resolveApiUser($request);

        if ($authRes = $this->rejectIfNotAuthenticatedIfNeeded($uploader)) {
            return $authRes;
        }

        $totalSize = (int) $request->input('size');

        // Checked up front so a large upload fails immediately rather than
        // after the client has spent minutes sending chunks.
        if ($quotaRes = $this->rejectIfOverQuota($uploader, $totalSize)) {
            return $quotaRes;
        }

        $maxBytes = $this->maxUploadKilobytes() * 1024;

        if ($totalSize > $maxBytes) {
            return response()->json([
                'error' => 'File exceeds the maximum upload size.',
                'max_bytes' => $maxBytes,
            ], 413);
        }

        $token = bin2hex(random_bytes(24));
        $storagePath = 'uploads/' . $token . '.part';

        $directory = UploadSession::storageDirectory();

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        // Create the (empty) target now so the first chunk has somewhere to go.
        touch(UploadSession::storageDirectory() . '/' . $token . '.part');

        $session = UploadSession::create([
            'token' => $token,
            'user_id' => $uploader?->id,
            'original_name' => $request->input('name'),
            'mime' => $request->input('mime'),
            'total_size' => $totalSize,
            'received_size' => 0,
            'storage_path' => $storagePath,
            'password' => $request->input('password') ?: null,
            'max_downloads' => $request->input('max_downloads') ?: null,
            'expires_minutes' => $request->input('expires') ?: null,
            'expires_at' => now()->addHours(UploadSession::LIFETIME_HOURS),
        ]);

        return response()->json([
            'token' => $session->token,
            'offset' => 0,
            'chunk_size' => $this->recommendedChunkSize(),
        ], 201);
    }

    public function status(Request $request, string $token)
    {
        $session = $this->findSession($request, $token);

        if (!$session instanceof UploadSession) {
            return $session;
        }

        return response()->json([
            'token' => $session->token,
            'offset' => $session->bytesOnDisk(),
            'total' => $session->total_size,
            'complete' => $session->isComplete(),
        ]);
    }

    public function append(Request $request, string $token)
    {
        $session = $this->findSession($request, $token);

        if (!$session instanceof UploadSession) {
            return $session;
        }

        $request->validate([
            'offset' => 'required|integer|min:0',
            'chunk' => 'required|file',
        ]);

        $offset = (int) $request->input('offset');
        $current = $session->bytesOnDisk();

        // The client and server must agree on where the file stands, otherwise
        // a retried or reordered request would corrupt the result.
        if ($offset !== $current) {
            return response()->json([
                'error' => 'Offset mismatch.',
                'expected' => $current,
            ], 409);
        }

        $chunk = $request->file('chunk');
        $chunkSize = $chunk->getSize() ?? 0;

        if ($current + $chunkSize > $session->total_size) {
            return response()->json([
                'error' => 'Chunk exceeds the declared file size.',
            ], 422);
        }

        $target = fopen($session->absolutePath(), 'ab');

        if ($target === false) {
            return response()->json(['error' => 'Could not open the upload.'], 500);
        }

        try {
            // Locked because two concurrent chunk requests for the same session
            // would otherwise interleave their writes.
            if (!flock($target, LOCK_EX)) {
                return response()->json(['error' => 'Upload is busy.'], 409);
            }

            // Re-check under the lock: the offset could have moved between the
            // comparison above and acquiring it.
            $lockedSize = $session->bytesOnDisk();

            if ($lockedSize !== $offset) {
                flock($target, LOCK_UN);

                return response()->json([
                    'error' => 'Offset mismatch.',
                    'expected' => $lockedSize,
                ], 409);
            }

            $source = fopen($chunk->getRealPath(), 'rb');

            if ($source === false) {
                flock($target, LOCK_UN);

                return response()->json(['error' => 'Could not read the chunk.'], 400);
            }

            stream_copy_to_stream($source, $target);
            fclose($source);
            fflush($target);
            flock($target, LOCK_UN);
        }
        finally {
            fclose($target);
        }

        $received = $session->bytesOnDisk();
        $session->forceFill(['received_size' => $received])->save();

        return response()->json([
            'offset' => $received,
            'total' => $session->total_size,
            'complete' => $session->isComplete(),
        ]);
    }

    public function complete(Request $request, string $token)
    {
        $session = $this->findSession($request, $token);

        if (!$session instanceof UploadSession) {
            return $session;
        }

        $received = $session->bytesOnDisk();

        if ($received !== $session->total_size) {
            return response()->json([
                'error' => 'Upload is incomplete.',
                'offset' => $received,
                'total' => $session->total_size,
            ], 409);
        }

        /** @var User|null */
        $uploader = $session->user_id ? User::find($session->user_id) : null;

        // Re-checked at the end as well: other uploads may have consumed the
        // allowance while this one was streaming.
        if ($uploader && !$uploader->canStore($received)) {
            $session->expire();

            return response()->json(['error' => 'Storage quota exceeded.'], 413);
        }

        do {
            $shortCode = $this->generateShortcode();
        } while (File::where('short_code', $shortCode)->exists());

        $password = $session->password;
        $destination = File::storageDirectory() . '/' . $shortCode;

        if (!is_dir(File::storageDirectory())) {
            mkdir(File::storageDirectory(), 0775, true);
        }

        if ($password) {
            PasswordCrypto::encryptFile($session->absolutePath(), $destination, $password);
            @unlink($session->absolutePath());
        } else {
            rename($session->absolutePath(), $destination);
        }

        $size = filesize($destination) ?: 0;
        $mime = $session->mime ?: $this->detectMime($destination);
        $expiresMinutes = $session->expires_minutes;

        if ($uploader) {
            $expireDate = $expiresMinutes ? now()->addMinutes($expiresMinutes) : null;
        } else {
            $expireDate = $expiresMinutes && $expiresMinutes <= 10080
                ? now()->addMinutes($expiresMinutes)
                : now()->addDays(7);
        }

        File::create([
            'short_code' => $shortCode,
            'original_name' => $session->original_name,
            'ext' => pathinfo($session->original_name, PATHINFO_EXTENSION) ?: null,
            'mime' => $mime,
            'password' => $password ? Hash::make($password) : null,
            'user_id' => $uploader?->id,
            'expires' => $expireDate,
            'size' => $size,
            'max_downloads' => $session->max_downloads,
        ]);

        if ($uploader) {
            $uploader->increment('storage_used', $size);
        }

        if (!$password && Thumbnailer::supports($mime)) {
            GenerateThumbnail::dispatch($destination, $shortCode, $mime);
        }

        $session->delete();

        return response()->json([
            'url' => url("/f/$shortCode"),
            'short_code' => $shortCode,
        ], 201);
    }

    public function destroy(Request $request, string $token)
    {
        $session = $this->findSession($request, $token);

        if (!$session instanceof UploadSession) {
            return $session;
        }

        $session->expire();

        return response()->json(['message' => 'Upload cancelled'], 204);
    }

    /**
     * @return UploadSession|\Illuminate\Http\JsonResponse
     */
    private function findSession(Request $request, string $token)
    {
        /** @var UploadSession|null */
        $session = UploadSession::where('token', $token)->first();

        if (!$session || $session->expires_at->isPast()) {
            return response()->json(['error' => 'Upload session not found.'], 404);
        }

        if (!$session->belongsToUser($this->resolveApiUser($request))) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return $session;
    }

    private function detectMime(string $path): string
    {
        if (function_exists('mime_content_type')) {
            return mime_content_type($path) ?: 'application/octet-stream';
        }

        return 'application/octet-stream';
    }

    /**
     * Kept comfortably under post_max_size so a chunk is never rejected by PHP
     * before the application sees it.
     */
    private function recommendedChunkSize(): int
    {
        $postMax = File::expandPHPFileSize((string) ini_get('post_max_size'));
        $uploadMax = File::expandPHPFileSize((string) ini_get('upload_max_filesize'));

        $limits = array_filter([$postMax, $uploadMax], fn ($value) => $value > 0);
        $ceiling = $limits === [] ? 8 * 1024 * 1024 : min($limits);

        // Half the smallest limit, capped at 8 MiB and floored at 256 KiB.
        return (int) max(262144, min(8 * 1024 * 1024, intdiv($ceiling, 2)));
    }
}
