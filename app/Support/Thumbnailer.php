<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Generates small preview images for uploads.
 *
 * File listings used to point <img> straight at the original upload, so a page
 * of fifteen 4 MB photos transferred 60 MB to draw fifteen 128px squares — and
 * every one of those requests went through the full application stack rather
 * than the web server's static handler.
 */
class Thumbnailer
{
    /** Longest edge, in pixels. */
    public const SIZE = 320;

    private const DISK = 'local';
    private const DIRECTORY = 'thumbnails';

    /**
     * Formats GD can decode. SVG is excluded on purpose: it is a scriptable
     * document, and it is never rendered inline anywhere in the app.
     */
    private const SUPPORTED = [
        'image/jpeg',
        'image/pjpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
    ];

    public static function supports(?string $mime): bool
    {
        return $mime !== null
            && in_array(strtolower($mime), self::SUPPORTED, true)
            && function_exists('imagecreatetruecolor');
    }

    public static function pathFor(string $key): string
    {
        return self::DIRECTORY . '/' . $key . '.webp';
    }

    public static function exists(string $key): bool
    {
        return Storage::disk(self::DISK)->exists(self::pathFor($key));
    }

    public static function absolutePathFor(string $key): string
    {
        return Storage::disk(self::DISK)->path(self::pathFor($key));
    }

    public static function delete(string $key): void
    {
        Storage::disk(self::DISK)->delete(self::pathFor($key));
    }

    /**
     * Build a thumbnail from a source image, returning its path on the disk or
     * null when the source cannot be decoded.
     */
    public static function generate(string $sourcePath, string $key, ?string $mime = null): ?string
    {
        if (!is_file($sourcePath) || !self::supports($mime)) {
            return null;
        }

        // Guard against decompression bombs: a small file can declare enormous
        // dimensions, and GD allocates 4 bytes per pixel while decoding.
        $info = @getimagesize($sourcePath);

        if (!$info || $info[0] < 1 || $info[1] < 1) {
            return null;
        }

        [$width, $height] = $info;

        if (($width * $height) > 80_000_000) {
            return null;
        }

        $source = @imagecreatefromstring(file_get_contents($sourcePath) ?: '');

        if (!$source) {
            return null;
        }

        try {
            $scale = min(self::SIZE / $width, self::SIZE / $height, 1);
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));

            $target = imagecreatetruecolor($targetWidth, $targetHeight);

            // Keep transparency rather than turning it black.
            imagealphablending($target, false);
            imagesavealpha($target, true);

            imagecopyresampled(
                $target,
                $source,
                0, 0, 0, 0,
                $targetWidth, $targetHeight,
                $width, $height
            );

            $directory = dirname(self::absolutePathFor($key));

            if (!is_dir($directory)) {
                mkdir($directory, 0775, true);
            }

            $destination = self::absolutePathFor($key);

            $written = function_exists('imagewebp')
                ? imagewebp($target, $destination, 82)
                : imagejpeg($target, $destination, 82);

            imagedestroy($target);

            return $written ? self::pathFor($key) : null;
        }
        finally {
            imagedestroy($source);
        }
    }
}
