<?php

namespace App\Models;

use App\Expireable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Directory extends Model implements Expireable
{
    protected $table = 'directories';

    protected $fillable = [
        'short_code',
        'name',
        'description',
        'password',
        'expires',
        'user_id',
        'size',
    ];

    protected $casts = [
        'expires' => 'datetime',
        'size' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(DirectoryItem::class);
    }

    public function files()
    {
        return $this->hasMany(DirectoryItem::class)->where('type', DirectoryItem::TYPE_FILE);
    }

    public function folders()
    {
        return $this->hasMany(DirectoryItem::class)->where('type', DirectoryItem::TYPE_FOLDER);
    }

    public function isExpired(): bool
    {
        return $this->expires && $this->expires->isPast();
    }

    public function expire(): void
    {
        if ($this->user_id && $this->size > 0) {
            /** @var User|null $user */
            $user = User::find($this->user_id);

            if ($user) {
                $user->storage_used = max(0, $user->storage_used - $this->size);
                $user->save();
            }
        }

        Storage::disk('local')->deleteDirectory("directories/$this->short_code");
        $this->delete();
    }

    public static function deleteExpired(): int
    {
        $directories = self::whereNotNull('expires')
            ->where('expires', '<', now())
            ->get();

        $count = $directories->count();

        foreach ($directories as $directory) {
            $directory->expire();
        }

        return $count;
    }
}