<?php

namespace App\Console\Commands;

use App\Models\Directory;
use App\Models\DirectoryItem;
use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Checks that what the database believes and what is on disk agree, and that
 * the web user can actually reach it.
 *
 * The failure this exists for is quiet: files are present and root can list
 * them, but the PHP-FPM user cannot traverse the directory, so every download
 * returns 404. Run it as the web user to test the real conditions:
 *
 *   docker compose exec -u www-data app php artisan shup:doctor
 */
class Doctor extends Command
{
    protected $signature = 'shup:doctor {--sample=5 : How many stored files to test-read}';

    protected $description = 'Diagnose storage paths, permissions, and database/disk agreement';

    private array $problems = [];
    private array $fixes = [];

    public function handle(): int
    {
        $this->line('');
        $this->line('<options=bold>Shup storage check</>');

        $this->reportIdentity();
        $this->reportPaths();
        $this->reportReadability();
        $this->reportRecordsVersusDisk();

        $this->line('');

        if ($this->problems === []) {
            $this->info('No problems found. Storage and database agree.');

            return self::SUCCESS;
        }

        $this->error(count($this->problems) . ' problem(s) found:');

        foreach ($this->problems as $problem) {
            $this->line("  - $problem");
        }

        if ($this->fixes !== []) {
            $this->line('');
            $this->line('<options=bold>Suggested fix</>');

            foreach (array_unique($this->fixes) as $fix) {
                $this->line("  $fix");
            }
        }

        $this->line('');

        return self::FAILURE;
    }

    private function reportIdentity(): void
    {
        $user = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $name = 'unknown';

        if ($user !== null && function_exists('posix_getpwuid')) {
            $name = posix_getpwuid($user)['name'] ?? 'unknown';
        }

        $this->line('');
        $this->line("Running as: <info>$name</info>" . ($user !== null ? " (uid $user)" : ''));

        if ($name === 'root') {
            $this->warn('  Root can read everything, so this run cannot prove the web user can.');
            $this->warn('  Re-run with: docker compose exec -u www-data app php artisan shup:doctor');
        }
    }

    private function reportPaths(): void
    {
        $this->line('');
        $this->line('<options=bold>Paths</>');

        $disk = Storage::disk('local');
        $root = $disk->path('');

        $targets = [
            'disk root' => rtrim($root, '/'),
            'files/' => File::storageDirectory(),
            'directories/' => $disk->path('directories'),
            'thumbnails/' => $disk->path('thumbnails'),
            'uploads/' => $disk->path('uploads'),
        ];

        $rows = [];

        foreach ($targets as $label => $path) {
            clearstatcache(true, $path);

            if (!is_dir($path)) {
                // Only the first two must exist; the rest are created on demand.
                $required = in_array($label, ['disk root', 'files/'], true);
                $rows[] = [$label, $this->shorten($path), '—', $required ? 'MISSING' : 'not created yet'];

                if ($required) {
                    $this->problems[] = "$label does not exist at $path";
                }

                continue;
            }

            $readable = is_readable($path);
            $state = $readable ? 'ok' : 'NOT READABLE';

            if (!$readable) {
                $this->problems[] = "$label exists but this user cannot read it ($path)";
                $this->fixes[] = 'docker compose exec app chown -R www-data:www-data storage/app';
            }

            $rows[] = [$label, $this->shorten($path), $this->describeMode($path), $state];
        }

        $this->table(['What', 'Path', 'Owner / mode', 'State'], $rows);

        // A copy without the trailing "/." nests the tree one level too deep.
        $nested = rtrim($disk->path(''), '/') . '/private';

        if (is_dir($nested)) {
            $this->problems[] = "Found a nested 'private' directory at $nested";
            $this->fixes[] = "# the copy landed one level too deep; move it up:";
            $this->fixes[] = "docker compose exec app sh -c 'cp -a {$nested}/. " . rtrim($disk->path(''), '/') . "/ && rm -rf $nested'";
        }
    }

    private function reportReadability(): void
    {
        $this->line('');
        $this->line('<options=bold>Reading stored files</>');

        $sample = max(1, (int) $this->option('sample'));
        $files = File::orderBy('id')->limit($sample)->get();

        if ($files->isEmpty()) {
            $this->line('  No file records to test.');

            return;
        }

        $unreadable = 0;
        $absent = 0;

        foreach ($files as $file) {
            $path = $file->absolutePath();
            clearstatcache(true, $path);

            if (!is_file($path)) {
                $absent++;
                $this->line("  <fg=yellow>absent</>     {$file->short_code}");
                continue;
            }

            if (@fopen($path, 'rb') === false) {
                $unreadable++;
                $this->line("  <fg=red>unreadable</> {$file->short_code}  — present, but this user cannot open it");
                continue;
            }

            $this->line("  <fg=green>ok</>         {$file->short_code}");
        }

        if ($unreadable > 0) {
            $this->problems[] = "$unreadable of the sampled files are present but unreadable by this user";
            $this->fixes[] = 'docker compose exec app chown -R www-data:www-data storage/app';
        }

        if ($absent > 0) {
            $this->problems[] = "$absent of the sampled files have no file on disk";
        }
    }

    private function reportRecordsVersusDisk(): void
    {
        $this->line('');
        $this->line('<options=bold>Records versus disk</>');

        $missingFiles = 0;
        File::orderBy('id')->chunkById(500, function ($files) use (&$missingFiles) {
            foreach ($files as $file) {
                if (!is_file($file->absolutePath())) {
                    $missingFiles++;
                }
            }
        });

        $disk = Storage::disk('local');
        $missingItems = 0;

        DirectoryItem::whereNotNull('storage_path')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$missingItems, $disk) {
                foreach ($rows as $row) {
                    if (!$disk->exists($row->storage_path)) {
                        $missingItems++;
                    }
                }
            });

        $known = File::pluck('short_code')->flip();
        $orphans = 0;

        if (is_dir(File::storageDirectory())) {
            foreach ($disk->files('files') as $path) {
                if (!$known->has(basename($path))) {
                    $orphans++;
                }
            }
        }

        $fileCount = File::count();
        $itemCount = DirectoryItem::whereNotNull('storage_path')->count();

        $this->table(
            ['What', 'Rows', 'Present', 'Missing'],
            [
                ['files', $fileCount, $fileCount - $missingFiles, $missingFiles],
                ['directory items', $itemCount, $itemCount - $missingItems, $missingItems],
                ['directories', Directory::count(), '—', '—'],
            ]
        );

        $this->line("  Blobs on disk with no record: $orphans");

        if ($missingFiles > 0) {
            $this->problems[] = "$missingFiles file record(s) have no file on disk";
        }

        if ($missingItems > 0) {
            $this->problems[] = "$missingItems directory item(s) have no file on disk";
        }

        if ($missingFiles > 0 && $missingFiles === $fileCount && $fileCount > 0) {
            $this->fixes[] = '# every file is missing, so this is a copy problem rather than drift:';
            $this->fixes[] = 'docker compose cp storage/app/private/. app:/var/www/html/storage/app/private/';
            $this->fixes[] = 'docker compose exec app chown -R www-data:www-data storage/app';
        }
    }

    private function describeMode(string $path): string
    {
        $stat = @stat($path);

        if (!$stat) {
            return 'unknown';
        }

        $owner = $stat['uid'];
        $group = $stat['gid'];

        if (function_exists('posix_getpwuid')) {
            $owner = posix_getpwuid($stat['uid'])['name'] ?? $stat['uid'];
            $group = posix_getgrgid($stat['gid'])['name'] ?? $stat['gid'];
        }

        return sprintf('%s:%s %04o', $owner, $group, $stat['mode'] & 0777);
    }

    private function shorten(string $path): string
    {
        return str_replace(base_path() . '/', '', $path);
    }
}
