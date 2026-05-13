<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DirectoryItem extends Model
{
    public const TYPE_FILE = 'file';
    public const TYPE_FOLDER = 'folder';

    protected $table = 'directory_items';

    protected $fillable = [
        'directory_id',
        'type',
        'path',
        'path_hash',
        'name',
        'mime',
        'size',
        'storage_path',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (DirectoryItem $item) {
            $item->path_hash = self::pathHash($item->path);

            if (!$item->name) {
                $item->name = self::nameFromPath($item->path);
            }
        });
    }

    public function directory()
    {
        return $this->belongsTo(Directory::class);
    }

    public function isFile(): bool
    {
        return $this->type === self::TYPE_FILE;
    }

    public function isFolder(): bool
    {
        return $this->type === self::TYPE_FOLDER;
    }

    public function parentPath(): string
    {
        return self::parentPathFor($this->path);
    }

    public function encodedPath(): string
    {
        return self::encodePath($this->path);
    }

    public function publicUrl(): string
    {
        $path = $this->encodedPath();

        return url('/d/' . $this->directory->short_code . ($path ? "/$path" : ''));
    }

    public static function pathHash(string $path): string
    {
        return hash('sha256', $path);
    }

    public static function parentPathFor(string $path): string
    {
        $position = strrpos($path, '/');

        if ($position === false) {
            return '';
        }

        return substr($path, 0, $position);
    }

    public static function nameFromPath(string $path): string
    {
        $position = strrpos($path, '/');

        if ($position === false) {
            return $path;
        }

        return substr($path, $position + 1);
    }

    public static function encodePath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        return collect(explode('/', $path))
            ->map(fn (string $segment) => rawurlencode($segment))
            ->implode('/');
    }
}