<?php

namespace App\Models;

use App\Expireable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * An in-progress resumable upload.
 *
 * The partial file lives under `uploads/` on the local disk and is only
 * promoted into a real File once every byte has arrived.
 */
class UploadSession extends Model implements Expireable
{
    protected $table = 'upload_sessions';

    protected $fillable = [
        'token',
        'user_id',
        'original_name',
        'mime',
        'total_size',
        'received_size',
        'storage_path',
        'password',
        'max_downloads',
        'expires_minutes',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'total_size' => 'integer',
        'received_size' => 'integer',
    ];

    /** Unfinished uploads are abandoned after this long. */
    public const LIFETIME_HOURS = 24;

    public static function storageDirectory(): string
    {
        return Storage::disk('local')->path('uploads');
    }

    public function absolutePath(): string
    {
        return Storage::disk('local')->path($this->storage_path);
    }

    public function isComplete(): bool
    {
        return $this->received_size >= $this->total_size;
    }

    /**
     * Bytes already stored, taken from the file itself rather than the counter
     * so a client resuming after a crash is told the truth.
     */
    public function bytesOnDisk(): int
    {
        $path = $this->absolutePath();

        // PHP caches stat results per request, which would report a stale size
        // immediately after a chunk was appended.
        clearstatcache(true, $path);

        return is_file($path) ? (int) filesize($path) : 0;
    }

    public function belongsToUser(?User $user): bool
    {
        // Anonymous sessions are owned by whoever holds the token.
        if ($this->user_id === null) {
            return true;
        }

        return $user !== null && $user->id === $this->user_id;
    }

    public function expire(): void
    {
        $path = $this->absolutePath();

        if (is_file($path)) {
            @unlink($path);
        }

        $this->delete();
    }

    public static function deleteExpired(): int
    {
        $count = 0;

        self::where('expires_at', '<', now())
            ->orderBy('id')
            ->chunkById(200, function ($sessions) use (&$count) {
                foreach ($sessions as $session) {
                    try {
                        $session->expire();
                        $count++;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        return $count;
    }
}
