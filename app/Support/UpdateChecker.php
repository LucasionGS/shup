<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

class UpdateChecker
{
    public const CACHE_KEY = 'shup.update_status';

    public static function cachedStatus(): array
    {
        return Cache::get(self::CACHE_KEY, [
            'available' => false,
            'git_available' => false,
        ]);
    }

    public static function check(bool $force = false): array
    {
        if (!app()->environment('production') && !$force) {
            return self::storeStatus([
                'available' => false,
                'git_available' => false,
                'reason' => 'not_production',
            ]);
        }

        $gitVersion = self::runGit(['--version']);
        if (!$gitVersion['ok']) {
            return self::storeStatus([
                'available' => false,
                'git_available' => false,
                'reason' => 'git_unavailable',
            ]);
        }

        $insideWorkTree = self::runGit(['rev-parse', '--is-inside-work-tree']);
        if (!$insideWorkTree['ok'] || trim($insideWorkTree['output']) !== 'true') {
            return self::storeStatus([
                'available' => false,
                'git_available' => true,
                'reason' => 'not_git_repository',
            ]);
        }

        $branchResult = self::runGit(['rev-parse', '--abbrev-ref', 'HEAD']);
        $branch = trim($branchResult['output']);

        if (!$branchResult['ok'] || $branch === '' || $branch === 'HEAD') {
            return self::storeStatus([
                'available' => false,
                'git_available' => true,
                'reason' => 'detached_head',
            ]);
        }

        $upstreamResult = self::runGit(['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}']);
        $upstream = trim($upstreamResult['output']);

        if (!$upstreamResult['ok'] || $upstream === '') {
            return self::storeStatus([
                'available' => false,
                'git_available' => true,
                'branch' => $branch,
                'reason' => 'no_upstream',
            ]);
        }

        $remote = self::remoteFromUpstream($upstream);
        if ($remote === null) {
            return self::storeStatus([
                'available' => false,
                'git_available' => true,
                'branch' => $branch,
                'upstream' => $upstream,
                'reason' => 'no_remote',
            ]);
        }

        $fetchResult = self::runGit(['fetch', '--quiet', '--prune', $remote], timeout: 60);
        if (!$fetchResult['ok']) {
            return self::storeStatus([
                'available' => false,
                'git_available' => true,
                'branch' => $branch,
                'upstream' => $upstream,
                'remote' => $remote,
                'reason' => 'fetch_failed',
            ]);
        }

        $countsResult = self::runGit(['rev-list', '--left-right', '--count', 'HEAD...@{u}']);
        if (!$countsResult['ok']) {
            return self::storeStatus([
                'available' => false,
                'git_available' => true,
                'branch' => $branch,
                'upstream' => $upstream,
                'remote' => $remote,
                'reason' => 'compare_failed',
            ]);
        }

        [$ahead, $behind] = array_pad(preg_split('/\s+/', trim($countsResult['output'])), 2, 0);
        $ahead = (int) $ahead;
        $behind = (int) $behind;

        return self::storeStatus([
            'available' => $behind > 0,
            'git_available' => true,
            'branch' => $branch,
            'upstream' => $upstream,
            'remote' => $remote,
            'ahead' => $ahead,
            'behind' => $behind,
            'reason' => $behind > 0 ? 'behind_upstream' : 'current',
        ]);
    }

    private static function runGit(array $arguments, int $timeout = 15): array
    {
        try {
            $result = Process::path(base_path())
                ->timeout($timeout)
                ->run(array_merge(['git'], $arguments));
        } catch (Throwable $throwable) {
            return [
                'ok' => false,
                'output' => '',
                'error' => $throwable->getMessage(),
            ];
        }

        return [
            'ok' => $result->successful(),
            'output' => $result->output(),
            'error' => $result->errorOutput(),
        ];
    }

    private static function remoteFromUpstream(string $upstream): ?string
    {
        $remotesResult = self::runGit(['remote']);

        if (!$remotesResult['ok']) {
            return str_contains($upstream, '/') ? explode('/', $upstream, 2)[0] : null;
        }

        $remotes = array_filter(preg_split('/\R+/', trim($remotesResult['output'])) ?: []);
        usort($remotes, fn (string $first, string $second) => strlen($second) <=> strlen($first));

        foreach ($remotes as $remote) {
            if (str_starts_with($upstream, $remote . '/')) {
                return $remote;
            }
        }

        return str_contains($upstream, '/') ? explode('/', $upstream, 2)[0] : null;
    }

    private static function storeStatus(array $status): array
    {
        $status = array_merge($status, [
            'checked_at' => now()->toIso8601String(),
        ]);

        Cache::put(self::CACHE_KEY, $status, now()->addHours(6));

        return $status;
    }
}