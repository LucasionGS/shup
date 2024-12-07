<?php

namespace App\Models;

use App\Expireable;
use Illuminate\Database\Eloquent\Model;

class PasteBin extends Model implements Expireable
{
    protected $table = 'paste_bins';
    protected $fillable = [
        'content',
        'short_code',
        'password',
        'expires',
        'user_id',
        'size'
    ];

    public function expire(): void {
        $this->delete();
    }

    public static function deleteExpired(): int {
        return PasteBin::where('expires', '<', now())->delete();
    }
}
