<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Configuration extends Model
{
    protected $table = 'configurations';
    protected $fillable = ['key', 'value', 'type'];

    private const CACHE_KEY = 'shup.configuration.all';

    /**
     * All settings as a key => value map, cached.
     *
     * Every getValue() call used to be its own query, and the layout reads the
     * app title on every single render, so anonymous 404s were paying for
     * database round-trips too. The whole table is tiny, so it is cached as one
     * map and invalidated on write.
     */
    private static function cachedMap(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            return self::query()->pluck('value', 'key')->all();
        });
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function getValue(string $key, mixed $default = null): \Illuminate\Database\Eloquent\Collection|string|null
    {
        if (str_contains($key, "*")) {
            $key = str_replace("*", "%", $key);
            return self::where('key', 'like', $key)->get();
        }

        $value = self::cachedMap()[$key] ?? null;

        return ($value !== null && $value !== '') ? $value : $default;
    }

    public static function getString($key, string $default = null) { return (string) self::getValue($key, $default); }
    public static function getInt($key, int $default = null) { return (int) self::getValue($key, $default); }
    public static function getFloat($key, float $default = null) { return (float) self::getValue($key, $default); }
    public static function getBool($key, bool $default = null) { return (bool) self::getValue($key, $default); }
    public static function getArray($key, array $default = null) { return (array) self::getValue($key, $default); }

    public static function appTitle(): string
    {
        return trim(self::getString('app_title', 'Shup')) ?: 'Shup';
    }

    public static function set(string $key, mixed $value, ?string $type = null)
    {
        $config = self::firstOrNew(['key' => $key]);
        $config->value = (string)$value;
        $config->type = $type ?? gettype($value);
        $config->save();

        self::flushCache();
    }

    protected static function booted(): void
    {
        // Catch writes that bypass set(), such as direct model updates.
        static::saved(fn () => self::flushCache());
        static::deleted(fn () => self::flushCache());
    }
}
