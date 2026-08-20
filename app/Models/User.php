<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Symfony\Component\Uid\UuidV4;

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
        'api_token_hash',
        'api_token_encrypted',
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
            'api_token_encrypted' => 'encrypted',
            'api_token_last_used_at' => 'datetime',
        ];
    }

    /**
     * Issue a fresh API token, storing only its hash plus an APP_KEY-encrypted
     * copy. Returns the plaintext so the caller can show it to the user.
     */
    public function issueApiToken(): string
    {
        $token = (string) UuidV4::v4();

        $this->api_token_hash = self::hashApiToken($token);
        $this->api_token_encrypted = $token;

        return $token;
    }

    /**
     * The plaintext API token, decrypted for display. Null when no token is set
     * or when it cannot be decrypted (for example after an APP_KEY rotation).
     */
    public function getApiTokenAttribute(): ?string
    {
        try {
            return $this->api_token_encrypted;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A storage_limit of 0 means unlimited.
     */
    public function hasUnlimitedStorage(): bool
    {
        return (int) $this->storage_limit === 0;
    }

    /**
     * Bytes still available to this user, or null when unlimited.
     */
    public function remainingStorage(): ?int
    {
        if ($this->hasUnlimitedStorage()) {
            return null;
        }

        return max(0, (int) $this->storage_limit - (int) $this->storage_used);
    }

    /**
     * Whether this user may store an additional $bytes without exceeding quota.
     */
    public function canStore(int $bytes): bool
    {
        $remaining = $this->remainingStorage();

        return $remaining === null || $bytes <= $remaining;
    }

    /**
     * Hash an API token for storage/lookup. Tokens are high-entropy UUIDs, so a
     * single SHA-256 pass is sufficient (no need for a slow password hash) and
     * keeps lookups a plain indexed equality match.
     */
    public static function hashApiToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Resolve a user from a raw API token, accepting an optional "Bearer "
     * prefix so both the CLI (bare token) and standards-compliant clients work.
     */
    public static function findByApiToken(?string $rawToken): ?User
    {
        if (!$rawToken) {
            return null;
        }

        $token = trim($rawToken);

        if (stripos($token, 'bearer ') === 0) {
            $token = trim(substr($token, 7));
        }

        if ($token === '') {
            return null;
        }

        return static::firstWhere('api_token_hash', static::hashApiToken($token));
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
