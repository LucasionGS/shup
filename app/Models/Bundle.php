<?php

namespace App\Models;

use App\Expireable;
use Illuminate\Database\Eloquent\Model;

class Bundle extends Model implements Expireable
{
    protected $table = 'bundles';

    protected $fillable = [
        'short_code',
        'name',
        'description',
        'password',
        'expires',
        'user_id',
    ];

    protected $casts = [
        'expires' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(BundleItem::class)->orderBy('position');
    }

    public function isExpired(): bool
    {
        return $this->expires && $this->expires->isPast();
    }

    public function expire(): void
    {
        $this->delete();
    }

    public static function deleteExpired(): int
    {
        return self::whereNotNull('expires')
            ->where('expires', '<', now())
            ->delete();
    }
}