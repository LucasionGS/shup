<?php

namespace App\Models;

use App\Expireable;
use Illuminate\Database\Eloquent\Model;

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
        'size'
    ];

    /**
     * 
     * Delete a file from the database and the filesystem at the same time
     * @param \App\Models\File $file
     * @return void
     */
    public function expire(): void {
        if ($this->user_id) {
            /** @var User */
            $user = User::find($this->user_id);
            $user->decrement('storage_used', $this->size);
        }
        $path = "app/private/files/$this->short_code";
        $path = storage_path($path);
        unlink($path);
        $this->delete();
    }

    public static function deleteExpired(): int {
        $files = File::where('expires', '<', now())->get();
        $count = $files->count();
        foreach ($files as $file) {
            $file->expire();
        }
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

    public static function expandPHPFileSize(string $size) {
        $unit = $size[-1];
        $size = (int) $size[0];
        $units = ['B', 'K', 'M', 'G', 'T'];
        $unit = array_search($unit, $units);
        while ($unit > 0) {
            $size *= 1024;
            $unit--;
        }
        return $size;
    }
}
