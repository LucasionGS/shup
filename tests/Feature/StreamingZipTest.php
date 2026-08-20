<?php

namespace Tests\Feature;

use App\Support\StreamingZip;
use Tests\TestCase;

class StreamingZipTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir() . '/shup-zip-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workDir)) {
            foreach (glob($this->workDir . '/*') ?: [] as $file) {
                is_dir($file) ? $this->removeTree($file) : unlink($file);
            }

            rmdir($this->workDir);
        }

        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? $this->removeTree($file) : unlink($file);
        }

        rmdir($dir);
    }

    /**
     * @param array<string,string> $files zip path => contents
     */
    private function buildArchive(array $files, array $directories = []): string
    {
        $sources = [];

        foreach ($files as $zipPath => $contents) {
            $source = $this->workDir . '/src-' . md5($zipPath);
            file_put_contents($source, $contents);
            $sources[$zipPath] = $source;
        }

        ob_start();

        $zip = new StreamingZip();

        foreach ($directories as $directory) {
            $zip->addDirectory($directory);
        }

        foreach ($sources as $zipPath => $source) {
            $zip->addFileFromPath($zipPath, $source);
        }

        $zip->finish();

        $archive = $this->workDir . '/archive.zip';
        file_put_contents($archive, ob_get_clean());

        return $archive;
    }

    public function test_archive_passes_unzip_integrity_check(): void
    {
        if (!$this->hasUnzip()) {
            $this->markTestSkipped('unzip is not installed.');
        }

        $archive = $this->buildArchive([
            'notes.txt' => 'hello world',
            'nested/deep/data.bin' => random_bytes(200000),
            'empty.txt' => '',
        ], ['nested/', 'nested/deep/']);

        exec('unzip -t ' . escapeshellarg($archive) . ' 2>&1', $output, $status);

        // Exit status only: Info-ZIP and busybox report success with different
        // wording, and the byte-level check lives in the extraction test.
        $this->assertSame(
            0,
            $status,
            "unzip rejected the archive:\n" . implode("\n", $output)
        );
    }

    public function test_extracted_contents_match_the_originals(): void
    {
        if (!$this->hasUnzip()) {
            $this->markTestSkipped('unzip is not installed.');
        }

        $payload = random_bytes(300000);

        $archive = $this->buildArchive([
            'notes.txt' => 'hello world',
            'nested/deep/data.bin' => $payload,
        ]);

        $target = $this->workDir . '/out';
        mkdir($target, 0775, true);

        exec('unzip -qq ' . escapeshellarg($archive) . ' -d ' . escapeshellarg($target) . ' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertSame('hello world', file_get_contents("$target/notes.txt"));
        $this->assertSame($payload, file_get_contents("$target/nested/deep/data.bin"));
    }

    /**
     * The CRC is now accumulated while streaming rather than computed in a
     * separate hash_file() pass, so each file must be opened exactly once.
     */
    public function test_each_file_is_read_only_once(): void
    {
        $source = $this->workDir . '/counted.bin';
        file_put_contents($source, random_bytes(50000));

        $opens = 0;
        StreamOpenCounter::$onOpen = function () use (&$opens) {
            $opens++;
        };

        stream_wrapper_unregister('file');
        stream_wrapper_register('file', StreamOpenCounter::class);

        try {
            ob_start();
            $zip = new StreamingZip();
            $zip->addFileFromPath('counted.bin', $source);
            $zip->finish();
            ob_end_clean();
        }
        finally {
            stream_wrapper_restore('file');
            StreamOpenCounter::$onOpen = null;
        }

        $this->assertSame(
            1,
            $opens,
            'The payload should be opened once; a second open means the CRC pass is back.'
        );
    }

    private function hasUnzip(): bool
    {
        exec('command -v unzip', $output, $status);

        return $status === 0;
    }
}

/**
 * Minimal passthrough stream wrapper that counts how many times the archived
 * file is opened for reading.
 */
class StreamOpenCounter
{
    /** @var callable|null */
    public static $onOpen = null;

    /** @var resource|null */
    public $context;

    private $handle;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (str_contains($path, 'counted.bin') && str_contains($mode, 'r') && self::$onOpen) {
            (self::$onOpen)();
        }

        stream_wrapper_restore('file');
        $this->handle = fopen($path, $mode);
        stream_wrapper_unregister('file');
        stream_wrapper_register('file', self::class);

        return $this->handle !== false;
    }

    public function stream_read(int $count) { return fread($this->handle, $count); }
    public function stream_write(string $data) { return fwrite($this->handle, $data); }
    public function stream_tell() { return ftell($this->handle); }
    public function stream_eof() { return feof($this->handle); }
    public function stream_seek(int $offset, int $whence) { return fseek($this->handle, $offset, $whence) === 0; }
    public function stream_stat() { return fstat($this->handle); }
    public function stream_close() { return fclose($this->handle); }
    public function stream_flush() { return fflush($this->handle); }
    public function url_stat(string $path, int $flags) {
        stream_wrapper_restore('file');
        $stat = $flags & STREAM_URL_STAT_QUIET ? @stat($path) : stat($path);
        stream_wrapper_unregister('file');
        stream_wrapper_register('file', self::class);

        return $stat;
    }
}
