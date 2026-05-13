<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class BundleItem extends Model
{
    public const TYPE_FILE = 'file';
    public const TYPE_PASTE = 'paste';
    public const TYPE_SHORT_URL = 'short_url';

    protected $table = 'bundle_items';

    protected $fillable = [
        'bundle_id',
        'resource_type',
        'resource_id',
        'position',
    ];

    private bool $bundleResourceResolved = false;

    private ?Model $bundleResource = null;

    public function bundle()
    {
        return $this->belongsTo(Bundle::class);
    }

    public function resource(): ?Model
    {
        if ($this->bundleResourceResolved) {
            return $this->bundleResource;
        }

        $this->bundleResource = match ($this->resource_type) {
            self::TYPE_FILE => File::find($this->resource_id),
            self::TYPE_PASTE => PasteBin::find($this->resource_id),
            self::TYPE_SHORT_URL => ShortURL::find($this->resource_id),
            default => null,
        };

        $this->bundleResourceResolved = true;

        return $this->bundleResource;
    }

    public function isAvailable(): bool
    {
        $resource = $this->resource();

        if (!$resource) {
            return false;
        }

        if ($resource->expires && Carbon::parse($resource->expires)->isPast()) {
            return false;
        }

        return true;
    }

    public function typeLabel(): string
    {
        return match ($this->resource_type) {
            self::TYPE_FILE => 'File',
            self::TYPE_PASTE => 'Paste',
            self::TYPE_SHORT_URL => 'Short URL',
            default => 'Item',
        };
    }

    public function displayName(): string
    {
        $resource = $this->resource();

        return match ($this->resource_type) {
            self::TYPE_FILE => $resource?->original_name ?? 'Missing file',
            self::TYPE_PASTE => $resource ? "Paste {$resource->short_code}" : 'Missing paste',
            self::TYPE_SHORT_URL => $resource?->url ?? 'Missing short URL',
            default => 'Missing item',
        };
    }

    public function publicUrl(): ?string
    {
        $resource = $this->resource();

        if (!$resource) {
            return null;
        }

        return match ($this->resource_type) {
            self::TYPE_FILE => url("/f/$resource->short_code"),
            self::TYPE_PASTE => url("/p/$resource->short_code"),
            self::TYPE_SHORT_URL => url("/s/$resource->short_code"),
            default => null,
        };
    }

    public function isProtected(): bool
    {
        $resource = $this->resource();

        return (bool) ($resource?->password ?? false);
    }
}