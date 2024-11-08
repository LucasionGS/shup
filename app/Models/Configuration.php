<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Configuration extends Model
{
    protected $table = 'configurations';
    protected $fillable = ['key', 'value', 'type'];

    public static function getValue(string $key, mixed $default = null): \Illuminate\Database\Eloquent\Collection|string|null
    {
        if (str_contains($key, "*")) {
            $key = str_replace("*", "%", $key);
            return self::where('key', 'like', $key)->get();
        }
        $config = self::where('key', $key)->first();
        return ($config && $config->value) ? $config->value : $default;
    }

    public static function getString($key, string $default = null) { return (string) self::getValue($key, $default); }
    public static function getInt($key, int $default = null) { return (int) self::getValue($key, $default); }
    public static function getFloat($key, float $default = null) { return (float) self::getValue($key, $default); }
    public static function getBool($key, bool $default = null) { return (bool) self::getValue($key, $default); }
    public static function getArray($key, array $default = null) { return (array) self::getValue($key, $default); }

    public static function set(string $key, mixed $value, ?string $type = null)
    {
        $config = self::firstOrNew(['key' => $key]);
        $config->value = (string)$value;
        $config->type = $type ?? gettype($value);
        $config->save();
    }
}
