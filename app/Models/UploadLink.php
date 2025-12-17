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
        'expires',
    ];

    protected $casts = [
        'used' => 'boolean',
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
