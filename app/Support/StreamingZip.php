<?php

namespace App\Support;

/**
 * Streams a store-only ZIP archive straight to the output buffer.
 *
 * Each file used to be read twice: once by hash_file() to compute the CRC for
 * the local header, and again to stream the bytes. Setting the data-descriptor
 * flag lets the CRC and sizes be written *after* the payload, so the CRC is now
 * accumulated while streaming and every file is read exactly once.
 *
 * ZIP64 records are emitted when an entry, the archive, or the entry count
 * exceeds the 32-bit limits, which previously truncated silently and produced a
 * corrupt archive.
 */
class StreamingZip
{
    private const UTF8_FLAG = 0x0800;

    /** Sizes and CRC follow the payload in a data descriptor. */
    private const DATA_DESCRIPTOR_FLAG = 0x0008;

    private const ZIP64_LIMIT = 0xffffffff;
    private const ZIP64_COUNT_LIMIT = 0xffff;

    private const VERSION_STORE = 20;
    private const VERSION_ZIP64 = 45;

    private const CHUNK_BYTES = 1048576;

    private array $centralDirectory = [];

    private int $offset = 0;

    public function addDirectory(string $zipPath): void
    {
        $zipPath = rtrim($this->normalizeZipPath($zipPath), '/') . '/';

        if ($zipPath === '/') {
            return;
        }

        $this->writeEntry($zipPath, null, time(), true);
    }

    public function addFileFromPath(string $zipPath, string $filesystemPath): void
    {
        if (!is_file($filesystemPath)) {
            return;
        }

        $this->writeEntry(
            $this->normalizeZipPath($zipPath),
            $filesystemPath,
            filemtime($filesystemPath) ?: time(),
            false
        );
    }

    public function finish(): void
    {
        $centralDirectoryOffset = $this->offset;

        foreach ($this->centralDirectory as $entry) {
            $this->writeCentralDirectoryEntry($entry);
        }

        $centralDirectorySize = $this->offset - $centralDirectoryOffset;
        $entryCount = count($this->centralDirectory);

        $needsZip64 = $entryCount > self::ZIP64_COUNT_LIMIT
            || $centralDirectoryOffset > self::ZIP64_LIMIT
            || $centralDirectorySize > self::ZIP64_LIMIT;

        if ($needsZip64) {
            $this->writeZip64EndRecords($entryCount, $centralDirectorySize, $centralDirectoryOffset);
        }

        $this->write(pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            min($entryCount, self::ZIP64_COUNT_LIMIT),
            min($entryCount, self::ZIP64_COUNT_LIMIT),
            min($centralDirectorySize, self::ZIP64_LIMIT),
            min($centralDirectoryOffset, self::ZIP64_LIMIT),
            0
        ));
    }

    private function writeEntry(string $zipPath, ?string $filesystemPath, int $timestamp, bool $isDirectory): void
    {
        [$dosTime, $dosDate] = $this->dosDateTime($timestamp);
        $entryOffset = $this->offset;

        // Directories and empty entries carry no payload, so their sizes are
        // known up front and need no data descriptor.
        $useDescriptor = $filesystemPath !== null;

        $flags = self::UTF8_FLAG | ($useDescriptor ? self::DATA_DESCRIPTOR_FLAG : 0);

        // The local header is written before the payload is read, so ZIP64 has
        // to be decided from the size on disk rather than the streamed total.
        $declaredSize = $filesystemPath !== null ? (filesize($filesystemPath) ?: 0) : 0;
        $useZip64 = $declaredSize > self::ZIP64_LIMIT || $entryOffset > self::ZIP64_LIMIT;

        $extraField = $useZip64 ? $this->zip64LocalExtraField() : '';

        $header = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            $useZip64 ? self::VERSION_ZIP64 : self::VERSION_STORE,
            $flags,
            0,
            $dosTime,
            $dosDate,
            0,
            $useDescriptor ? 0 : ($useZip64 ? self::ZIP64_LIMIT : 0),
            $useDescriptor ? 0 : ($useZip64 ? self::ZIP64_LIMIT : 0),
            strlen($zipPath),
            strlen($extraField)
        );

        $this->write($header . $zipPath . $extraField);

        $crc = 0;
        $size = 0;

        if ($filesystemPath !== null) {
            [$crc, $size] = $this->streamPayload($filesystemPath);

            $this->writeDataDescriptor($crc, $size, $useZip64);
        }

        $this->centralDirectory[] = [
            'name' => $zipPath,
            'crc' => $crc,
            'size' => $size,
            'time' => $dosTime,
            'date' => $dosDate,
            'offset' => $entryOffset,
            'is_directory' => $isDirectory,
            'zip64' => $useZip64 || $size > self::ZIP64_LIMIT || $entryOffset > self::ZIP64_LIMIT,
            'descriptor' => $useDescriptor,
        ];
    }

    /**
     * Copy the file to output while accumulating its CRC in the same pass.
     *
     * @return array{0:int,1:int} CRC and byte count
     */
    private function streamPayload(string $filesystemPath): array
    {
        $handle = fopen($filesystemPath, 'rb');

        if ($handle === false) {
            throw new \RuntimeException('Could not read file for ZIP stream.');
        }

        $hash = hash_init('crc32b');
        $size = 0;

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, self::CHUNK_BYTES);

                if ($chunk === false) {
                    throw new \RuntimeException('Could not read file chunk for ZIP stream.');
                }

                if ($chunk === '') {
                    break;
                }

                hash_update($hash, $chunk);
                $size += strlen($chunk);

                $this->write($chunk);
            }
        }
        finally {
            fclose($handle);
        }

        return [(int) hexdec(hash_final($hash)), $size];
    }

    private function writeDataDescriptor(int $crc, int $size, bool $useZip64): void
    {
        // Signature is optional in the specification but universally expected.
        $this->write(pack('VV', 0x08074b50, $crc));

        $this->write($useZip64
            ? $this->packLong($size) . $this->packLong($size)
            : pack('VV', $size, $size));
    }

    private function writeCentralDirectoryEntry(array $entry): void
    {
        $useZip64 = $entry['zip64'];

        $extraField = $useZip64
            ? $this->zip64CentralExtraField($entry['size'], $entry['offset'])
            : '';

        $flags = self::UTF8_FLAG | ($entry['descriptor'] ? self::DATA_DESCRIPTOR_FLAG : 0);

        $header = pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            $useZip64 ? self::VERSION_ZIP64 : self::VERSION_STORE,
            $useZip64 ? self::VERSION_ZIP64 : self::VERSION_STORE,
            $flags,
            0,
            $entry['time'],
            $entry['date'],
            $entry['crc'],
            $useZip64 ? self::ZIP64_LIMIT : $entry['size'],
            $useZip64 ? self::ZIP64_LIMIT : $entry['size'],
            strlen($entry['name']),
            strlen($extraField),
            0,
            0,
            0,
            $entry['is_directory'] ? 0x10 : 0x20,
            $useZip64 ? self::ZIP64_LIMIT : $entry['offset']
        );

        $this->write($header . $entry['name'] . $extraField);
    }

    /**
     * Placeholder sizes in the local header; the real values arrive in the
     * data descriptor.
     */
    private function zip64LocalExtraField(): string
    {
        return pack('vv', 0x0001, 16) . $this->packLong(0) . $this->packLong(0);
    }

    private function zip64CentralExtraField(int $size, int $offset): string
    {
        return pack('vv', 0x0001, 24)
            . $this->packLong($size)
            . $this->packLong($size)
            . $this->packLong($offset);
    }

    private function writeZip64EndRecords(int $entryCount, int $centralDirectorySize, int $centralDirectoryOffset): void
    {
        $zip64EndOffset = $this->offset;

        $this->write(
            pack('V', 0x06064b50)
            . $this->packLong(44) // size of this record minus 12
            . pack('vv', self::VERSION_ZIP64, self::VERSION_ZIP64)
            . pack('VV', 0, 0)
            . $this->packLong($entryCount)
            . $this->packLong($entryCount)
            . $this->packLong($centralDirectorySize)
            . $this->packLong($centralDirectoryOffset)
        );

        $this->write(
            pack('VV', 0x07064b50, 0)
            . $this->packLong($zip64EndOffset)
            . pack('V', 1)
        );
    }

    /** 64-bit little-endian, for PHP builds where 'P' availability varies. */
    private function packLong(int $value): string
    {
        return pack('VV', $value & 0xffffffff, ($value >> 32) & 0xffffffff);
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
