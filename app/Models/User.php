<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'api_token',
        'storage_limit',
        'storage_used',
        'role',
        'image',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function calculateStorage(
        bool $save = true
    ) {
        $size = File::where("user_id", $this->id)->select("size")->sum('size')
            + PasteBin::where("user_id", $this->id)->select("size")->sum('size')
            + ShortURL::where("user_id", $this->id)->select("size")->sum('size');

        if ($save) {
            $this->storage_used = $size;
            $this->save();
        }

        return $this->storage_used;
    }

    const ROLE_USER = 0;
    const ROLE_ADMIN = 1;
    const ROLE_CONTENT_MODERATOR = 2;
    
    public static $roles = [ // Order determines hierarchy. Higher index means higher role.
        // Lowest role
        User::ROLE_USER               => 'User',
        User::ROLE_CONTENT_MODERATOR  => 'Content Moderator',
        User::ROLE_ADMIN              => 'Admin',
        // Highest role
    ];

    public function getRoleName(): string
    {
        return static::$roles[$this->role] ?? 'Unknown';
    }

    /**
     * Returns true if the user has the given role or a higher one.
     * @param int $role
     * @param bool $exact If true, only returns true if the user has the exact role. Does not consider higher roles.
     * @return bool
     */
    public function isRole(int $role, bool $exact = false): bool
    {
        $r = $this->role;
        if ($r === $role) { return true; }
        if ($exact) { return false; }

        $roles = array_keys(self::$roles);
        $rIndex = array_search($r, $roles);
        $roleIndex = array_search($role, $roles);

        return $rIndex > $roleIndex;
    }

    /**
     * Returns true if the user is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->isRole(User::ROLE_ADMIN, exact: true); // Admin is highest, no need to check for higher roles
    }

    /**
     * Returns true if the user is a content moderator or higher.
     */
    public function isContentModerator(): bool
    {
        return $this->isRole(User::ROLE_CONTENT_MODERATOR);
    }

    /**
     * Returns true if the user is a regular user. False if they are any other role.
     */
    public function isUser(): bool
    {
        return $this->isRole(User::ROLE_USER, exact: true);
    }
}
