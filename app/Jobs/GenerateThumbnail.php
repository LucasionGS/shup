<?php

namespace App\Jobs;

use App\Support\Thumbnailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Builds a thumbnail off the request path, so an upload returns as soon as the
 * bytes are stored rather than waiting on image processing.
 */
class GenerateThumbnail implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(
        private readonly string $sourcePath,
        private readonly string $key,
        private readonly ?string $mime,
    ) {
    }

    public function handle(): void
    {
        if (Thumbnailer::exists($this->key)) {
            return;
        }

        Thumbnailer::generate($this->sourcePath, $this->key, $this->mime);
    }

    /**
     * A file deleted before the job ran is not a failure worth retrying.
     */
    public function failed(\Throwable $exception): void
    {
        report($exception);
    }
}
