<?php

namespace App\Models;

use App\Expireable;
use Illuminate\Database\Eloquent\Model;

class ShortURL extends Model implements Expireable
{
    protected $table = 'short_urls';
    protected $fillable = [
        'url',
        'short_code',
        'hits',
        'expires',
        'user_id',
        'size',
    ];

    public function expire(): void {
        $this->delete();
    }

    public static function deleteExpired(): int {
        return ShortURL::where('expires', '<', now())->delete();
    }
}
