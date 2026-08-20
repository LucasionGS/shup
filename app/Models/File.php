<?php

namespace App\Models;

use App\Expireable;
use Illuminate\Database\Eloquent\Model;
use App\Support\Thumbnailer;
use Illuminate\Support\Facades\Storage;

class File extends Model implements Expireable
{
    protected $table = 'files';
    protected $fillable = [
        'short_code',
        'original_name',
        'ext',
        'mime',
        'downloads',
        'password',
        'expires',
        'user_id',
        'size',
        'max_downloads',
    ];

    /**
     * Directory holding file blobs, resolved through the configured disk so the
     * storage location is defined in one place.
     */
    public static function storageDirectory(): string
    {
        return Storage::disk('local')->path('files');
    }

    public function absolutePath(): string
    {
        return self::storageDirectory() . '/' . $this->short_code;
    }

    /**
     * Whether this share has served all of its permitted downloads.
     */
    public function hasReachedDownloadLimit(): bool
    {
        return $this->max_downloads !== null
            && (int) $this->downloads >= (int) $this->max_downloads;
    }

    /**
     *
     * Delete a file from the database and the filesystem at the same time
     * @param \App\Models\File $file
     * @return void
     */
    public function expire(): void {
        if ($this->user_id) {
            /** @var User|null */
            $user = User::find($this->user_id);
            $user?->decrement('storage_used', $this->size);
        }

        // A missing blob used to throw here and abort the whole expiry sweep,
        // leaving every later expired item in place.
        $path = $this->absolutePath();

        if (is_file($path)) {
            @unlink($path);
        }

        Thumbnailer::delete($this->short_code);

        $this->delete();
    }

    public static function deleteExpired(): int {
        $count = 0;

        File::where('expires', '<', now())
            ->orderBy('id')
            ->chunkById(200, function ($files) use (&$count) {
                foreach ($files as $file) {
                    try {
                        $file->expire();
                        $count++;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        return $count;
    }

    public static function reduceFileSize($bytes) {
        $bytes = (int) $bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;
        while ($bytes >= 1024 && $unit < count($units) - 1) {
            $bytes /= 1024;
            $unit++;
        }
        return round($bytes, 2) . ' ' . $units[$unit];
    }

    public static function expandFileSize($size) {
        $size = explode(' ', $size);
        $unit = $size[1];
        $size = (int) $size[0];
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = array_search($unit, $units);
        while ($unit > 0) {
            $size *= 1024;
            $unit--;
        }
        return $size;
    }

    /**
     * Expand a PHP ini shorthand size ("512M", "2G") into bytes.
     *
     * This previously read `$size[0]` — the first *character* — so "512M" was
     * parsed as 5 MB. "2G" happened to be correct, which is why it went
     * unnoticed.
     */
    public static function expandPHPFileSize(string $size) {
        $size = trim($size);

        if ($size === '') {
            return 0;
        }

        $unit = strtoupper($size[-1]);
        $value = (int) $size; // leading-numeric cast: "512M" -> 512
        $units = ['B', 'K', 'M', 'G', 'T'];
        $exponent = array_search($unit, $units, true);

        if ($exponent === false) {
            return $value;
        }

        return $value * (1024 ** $exponent);
    }
}
