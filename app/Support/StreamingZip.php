<?php

namespace App\Support;

class StreamingZip
{
    private const UTF8_FLAG = 0x0800;

    private array $centralDirectory = [];

    private int $offset = 0;

    public function addDirectory(string $zipPath): void
    {
        $zipPath = rtrim($this->normalizeZipPath($zipPath), '/') . '/';

        if ($zipPath === '/') {
            return;
        }

        $this->writeEntry($zipPath, null, 0, 0, 0, true);
    }

    public function addFileFromPath(string $zipPath, string $filesystemPath): void
    {
        if (!is_file($filesystemPath)) {
            return;
        }

        $zipPath = $this->normalizeZipPath($zipPath);
        $size = filesize($filesystemPath) ?: 0;

        if ($size > 0xffffffff) {
            throw new \RuntimeException('ZIP64 files are not supported.');
        }

        $crc = (int) hexdec(hash_file('crc32b', $filesystemPath));

        $this->writeEntry($zipPath, $filesystemPath, $crc, $size, filemtime($filesystemPath) ?: time(), false);
    }

    public function finish(): void
    {
        $centralDirectoryOffset = $this->offset;

        foreach ($this->centralDirectory as $entry) {
            $header = pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                self::UTF8_FLAG,
                0,
                $entry['time'],
                $entry['date'],
                $entry['crc'],
                $entry['size'],
                $entry['size'],
                strlen($entry['name']),
                0,
                0,
                0,
                0,
                $entry['is_directory'] ? 0x10 : 0x20,
                $entry['offset']
            );

            $this->write($header . $entry['name']);
        }

        $centralDirectorySize = $this->offset - $centralDirectoryOffset;
        $entryCount = count($this->centralDirectory);

        $this->write(pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            $entryCount,
            $entryCount,
            $centralDirectorySize,
            $centralDirectoryOffset,
            0
        ));
    }

    private function writeEntry(string $zipPath, ?string $filesystemPath, int $crc, int $size, int $timestamp, bool $isDirectory): void
    {
        [$dosTime, $dosDate] = $this->dosDateTime($timestamp);
        $entryOffset = $this->offset;

        $header = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            self::UTF8_FLAG,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            strlen($zipPath),
            0
        );

        $this->write($header . $zipPath);

        if ($filesystemPath) {
            $handle = fopen($filesystemPath, 'rb');

            if ($handle === false) {
                throw new \RuntimeException('Could not read file for ZIP stream.');
            }

            while (!feof($handle)) {
                $chunk = fread($handle, 1024 * 1024);

                if ($chunk === false) {
                    fclose($handle);
                    throw new \RuntimeException('Could not read file chunk for ZIP stream.');
                }

                $this->write($chunk);
            }

            fclose($handle);
        }

        $this->centralDirectory[] = [
            'name' => $zipPath,
            'crc' => $crc,
            'size' => $size,
            'time' => $dosTime,
            'date' => $dosDate,
            'offset' => $entryOffset,
            'is_directory' => $isDirectory,
        ];
    }

    private function write(string $content): void
    {
        echo $content;
        $this->offset += strlen($content);

        if (function_exists('flush')) {
            flush();
        }
    }

    private function dosDateTime(int $timestamp): array
    {
        $date = getdate($timestamp);
        $year = max(1980, min(2107, (int) $date['year']));

        $dosTime = ((int) $date['hours'] << 11) | ((int) $date['minutes'] << 5) | ((int) floor($date['seconds'] / 2));
        $dosDate = (($year - 1980) << 9) | ((int) $date['mon'] << 5) | (int) $date['mday'];

        return [$dosTime, $dosDate];
    }

    private function normalizeZipPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        return ltrim($path, '/');
    }
}