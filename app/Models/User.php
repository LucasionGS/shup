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
        'accent_color',
    ];

    public const DEFAULT_ACCENT_COLOR = '#a78bfa';

    public static function accentColorPresets(): array
    {
        return [
            'Purple' => '#a78bfa',
            'Violet' => '#8b5cf6',
            'Rose' => '#fb7185',
            'Blue' => '#60a5fa',
            'Cyan' => '#22d3ee',
            'Green' => '#34d399',
            'Amber' => '#f59e0b',
            'Slate' => '#94a3b8',
        ];
    }

    public static function normalizeAccentColor(?string $color): ?string
    {
        if ($color === null) {
            return null;
        }

        $color = strtolower(trim($color));

        if ($color === '') {
            return null;
        }

        if (preg_match('/^[0-9a-f]{6}$/', $color)) {
            return "#$color";
        }

        if (preg_match('/^#[0-9a-f]{6}$/', $color)) {
            return $color;
        }

        return null;
    }

    public function accentColor(): string
    {
        return self::normalizeAccentColor($this->accent_color) ?? self::DEFAULT_ACCENT_COLOR;
    }

    public function accentThemeVariables(): array
    {
        $accent = self::normalizeAccentColor($this->accent_color);

        if (!$accent) {
            return [];
        }

        $accentRgb = self::hexToRgb($accent);
        $accentStrong = self::mixHex($accent, '#ffffff', 0.32);
        $accentStrongRgb = self::hexToRgb($accentStrong);
        $accentInk = self::readableTextColor($accentRgb);

        return [
            '--accent' => $accent,
            '--accent-strong' => $accentStrong,
            '--accent-rgb' => implode(', ', $accentRgb),
            '--accent-strong-rgb' => implode(', ', $accentStrongRgb),
            '--accent-ink' => $accentInk,
            '--success' => $accentStrong,
            '--success-rgb' => implode(', ', $accentStrongRgb),
        ];
    }

    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    private static function mixHex(string $fromHex, string $toHex, float $weight): string
    {
        $from = self::hexToRgb($fromHex);
        $to = self::hexToRgb($toHex);

        $mixed = array_map(function (int $fromChannel, int $toChannel) use ($weight) {
            return max(0, min(255, (int) round($fromChannel + (($toChannel - $fromChannel) * $weight))));
        }, $from, $to);

        return sprintf('#%02x%02x%02x', $mixed[0], $mixed[1], $mixed[2]);
    }

    private static function readableTextColor(array $rgb): string
    {
        $brightness = (($rgb[0] * 299) + ($rgb[1] * 587) + ($rgb[2] * 114)) / 1000;

        return $brightness > 150 ? '#170b2f' : '#f7f3ff';
    }

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
            + ShortURL::where("user_id", $this->id)->select("size")->sum('size')
            + Directory::where("user_id", $this->id)->select("size")->sum('size');

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
