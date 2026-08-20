<?php

namespace App\Models;

use App\Expireable;
use Illuminate\Database\Eloquent\Model;

class UploadLink extends Model implements Expireable
{
    protected $table = 'upload_links';
    protected $fillable = [
        'short_code',
        'user_id',
        'used',
        'multi_file',
        'expires',
    ];

    protected $casts = [
        'used' => 'boolean',
        'multi_file' => 'boolean',
        'expires' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isValid(): bool
    {
        if ($this->used) {
            return false;
        }

        if ($this->expires && $this->expires->isPast()) {
            return false;
        }

        return true;
    }

    public function markUsed(): void
    {
        $this->used = true;
        $this->save();
    }

    /**
     * Atomically claim this one-time link.
     *
     * The conditional update means only one of several concurrent requests can
     * flip `used` from false to true, so a burst against the same link can no
     * longer land more than one upload.
     */
    public function claim(): bool
    {
        if ($this->expires && $this->expires->isPast()) {
            return false;
        }

        $claimed = static::whereKey($this->getKey())
            ->where('used', false)
            ->update(['used' => true]);

        if ($claimed === 0) {
            return false;
        }

        $this->used = true;

        return true;
    }

    /**
     * Hand the link back when the upload it was claimed for was rejected.
     */
    public function release(): void
    {
        static::whereKey($this->getKey())->update(['used' => false]);
        $this->used = false;
    }

    public function expire(): void
    {
        $this->delete();
    }

    public static function deleteExpired(): int
    {
        $expired = self::where(function ($query) {
            $query->where('used', true)
                  ->orWhere(function ($q) {
                      $q->whereNotNull('expires')
                        ->where('expires', '<', now());
                  });
        })->get();

        $count = $expired->count();
        foreach ($expired as $link) {
            $link->expire();
        }
        
        return $count;
    }
}
