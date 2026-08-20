<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Copies the contents of a legacy SQLite database into the configured
 * (MariaDB/MySQL) connection.
 *
 * A raw SQLite dump is not portable to MariaDB — the type affinities, quoting
 * and boolean representation all differ — so this walks the tables row by row
 * through the query builder instead, which normalises those differences.
 */
class MigrateSqlite extends Command
{
    protected $signature = 'shup:migrate-sqlite
        {path : Path to the existing database.sqlite file}
        {--truncate : Empty the destination tables first}
        {--chunk=200 : Rows to copy per batch}';

    protected $description = 'Copy data from a legacy SQLite database into the configured database';

    /**
     * Parents before children, so foreign keys are always satisfiable.
     * Cache, session and queue tables are deliberately excluded: they are
     * transient, and the Docker stack keeps them in Redis anyway.
     */
    private const TABLE_ORDER = [
        'users',
        'configurations',
        'invited_users',
        'files',
        'short_urls',
        'paste_bins',
        'upload_links',
        'directories',
        'directory_items',
        'password_reset_tokens',
    ];

    public function handle(): int
    {
        $path = $this->argument('path');

        if (!is_file($path)) {
            $this->error("No SQLite database at: $path");

            return self::FAILURE;
        }

        $destination = config('database.default');

        if ($destination === 'sqlite') {
            $this->error('The default connection is still sqlite; point DB_CONNECTION at the target database first.');

            return self::FAILURE;
        }

        Config::set('database.connections.legacy_sqlite', [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $source = DB::connection('legacy_sqlite');
        $target = DB::connection($destination);

        $this->info("Copying from $path into the '$destination' connection.");

        $tables = array_filter(
            self::TABLE_ORDER,
            fn (string $table) => $this->tableExistsIn($source, $table) && Schema::hasTable($table)
        );

        if ($tables === []) {
            $this->error('No matching tables found. Run "php artisan migrate" against the destination first.');

            return self::FAILURE;
        }

        if ($this->option('truncate')) {
            $this->truncate($target, $tables);
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $summary = [];

        foreach ($tables as $table) {
            $copied = $this->copyTable($source, $target, $table, $chunk);
            $summary[] = [$table, $copied];

            if ($table === 'users') {
                $this->migrateApiTokens($source, $target);
            }
        }

        $this->newLine();
        $this->table(['Table', 'Rows copied'], $summary);
        $this->info('Done. Now reconcile the storage counters:');
        $this->line('  php artisan shup:recalculate_storage');
        $this->line('  php artisan shup:recalculate_physical_storage');

        return self::SUCCESS;
    }

    private function copyTable($source, $target, string $table, int $chunk): int
    {
        // Only copy columns that exist on both sides, so a destination schema
        // that has moved on (for example the api_token split) still imports.
        $sourceColumns = $this->columnsOf($source, $table);
        $targetColumns = Schema::getColumnListing($table);
        $shared = array_values(array_intersect($sourceColumns, $targetColumns));

        if ($shared === []) {
            $this->warn("  $table: no shared columns, skipped");

            return 0;
        }

        $dropped = array_diff($sourceColumns, $targetColumns);

        if ($dropped) {
            $this->warn("  $table: ignoring columns absent from the destination: " . implode(', ', $dropped));
        }

        $total = $source->table($table)->count();

        if ($total === 0) {
            $this->line("  $table: empty");

            return 0;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat("  $table: %current%/%max% [%bar%]");
        $bar->start();

        $copied = 0;
        $key = in_array('id', $sourceColumns, true) ? 'id' : null;

        $query = $source->table($table)->select($shared);

        $handler = function ($rows) use ($target, $table, &$copied, $bar) {
            $payload = array_map(fn ($row) => (array) $row, $rows->all());

            if ($payload === []) {
                return;
            }

            $target->table($table)->insert($payload);
            $copied += count($payload);
            $bar->advance(count($payload));
        };

        try {
            if ($key) {
                $query->orderBy($key)->chunk($chunk, $handler);
            } else {
                $query->chunk($chunk, $handler);
            }
        } catch (Throwable $e) {
            $bar->finish();
            $this->newLine();
            $this->error("  $table: " . $e->getMessage());

            throw $e;
        }

        $bar->finish();
        $this->newLine();

        return $copied;
    }

    /**
     * The legacy schema stored API tokens in plaintext in users.api_token,
     * which the current schema replaced with a hash plus an encrypted copy.
     * That column is therefore not copied by the generic pass, and without this
     * step every user would silently lose their token.
     */
    private function migrateApiTokens($source, $target): void
    {
        if (!in_array('api_token', $this->columnsOf($source, 'users'), true)) {
            return;
        }

        if (!Schema::hasColumn('users', 'api_token_hash')) {
            return;
        }

        $migrated = 0;

        $source->table('users')
            ->select('id', 'api_token')
            ->whereNotNull('api_token')
            ->orderBy('id')
            ->chunk(200, function ($users) use ($target, &$migrated) {
                foreach ($users as $user) {
                    $target->table('users')
                        ->where('id', $user->id)
                        ->update([
                            'api_token_hash' => hash('sha256', $user->api_token),
                            'api_token_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString($user->api_token),
                        ]);
                    $migrated++;
                }
            });

        $this->line("  users: carried over $migrated API token(s); existing CLI and ShareX configs keep working");
    }

    private function truncate($target, array $tables): void
    {
        $this->warn('Emptying destination tables.');

        $isMySql = in_array($target->getDriverName(), ['mysql', 'mariadb'], true);

        if ($isMySql) {
            $target->statement('SET FOREIGN_KEY_CHECKS=0');
        }

        foreach (array_reverse($tables) as $table) {
            $target->table($table)->truncate();
        }

        if ($isMySql) {
            $target->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function tableExistsIn($connection, string $table): bool
    {
        return $connection->getSchemaBuilder()->hasTable($table);
    }

    private function columnsOf($connection, string $table): array
    {
        return $connection->getSchemaBuilder()->getColumnListing($table);
    }
}
