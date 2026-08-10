<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\UpdateChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class UpdateCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_checker_marks_branch_as_out_of_date_when_behind_upstream(): void
    {
        $this->fakeGit([
            'git --version' => Process::result('git version 2.45.0'),
            'git rev-parse --is-inside-work-tree' => Process::result('true'),
            'git rev-parse --abbrev-ref HEAD' => Process::result('main'),
            'git rev-parse --abbrev-ref --symbolic-full-name @{u}' => Process::result('origin/main'),
            'git remote' => Process::result('origin'),
            'git fetch --quiet --prune origin' => Process::result(''),
            'git rev-list --left-right --count HEAD...@{u}' => Process::result('1 3'),
        ]);

        $status = UpdateChecker::check(force: true);

        $this->assertTrue($status['available']);
        $this->assertSame('main', $status['branch']);
        $this->assertSame('origin/main', $status['upstream']);
        $this->assertSame(1, $status['ahead']);
        $this->assertSame(3, $status['behind']);
        $this->assertSame($status, Cache::get(UpdateChecker::CACHE_KEY));
    }

    public function test_update_checker_hides_badge_state_when_git_is_unavailable(): void
    {
        $this->fakeGit([
            'git --version' => Process::result('', 'git not found', 127),
        ]);

        $status = UpdateChecker::check(force: true);

        $this->assertFalse($status['available']);
        $this->assertFalse($status['git_available']);
        $this->assertSame('git_unavailable', $status['reason']);
    }

    public function test_update_check_command_writes_cached_status(): void
    {
        $this->fakeGit([
            'git --version' => Process::result('git version 2.45.0'),
            'git rev-parse --is-inside-work-tree' => Process::result('true'),
            'git rev-parse --abbrev-ref HEAD' => Process::result('main'),
            'git rev-parse --abbrev-ref --symbolic-full-name @{u}' => Process::result('origin/main'),
            'git remote' => Process::result('origin'),
            'git fetch --quiet --prune origin' => Process::result(''),
            'git rev-list --left-right --count HEAD...@{u}' => Process::result('0 0'),
        ]);

        $this->artisan('shup:check_updates', ['--force' => true])
            ->expectsOutput('No update available. Status: current')
            ->assertExitCode(0);

        $this->assertFalse(Cache::get(UpdateChecker::CACHE_KEY)['available']);
    }

    public function test_admin_header_shows_update_badge_when_cached_status_is_available(): void
    {
        Cache::put(UpdateChecker::CACHE_KEY, [
            'available' => true,
            'git_available' => true,
            'branch' => 'main',
            'upstream' => 'origin/main',
            'behind' => 2,
            'checked_at' => now()->toIso8601String(),
        ], now()->addHour());

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Update available');
    }

    public function test_non_admin_header_does_not_show_update_badge(): void
    {
        Cache::put(UpdateChecker::CACHE_KEY, [
            'available' => true,
            'git_available' => true,
            'branch' => 'main',
            'upstream' => 'origin/main',
            'behind' => 2,
            'checked_at' => now()->toIso8601String(),
        ], now()->addHour());

        $user = User::factory()->create([
            'role' => User::ROLE_USER,
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Update available');
    }

    private function fakeGit(array $responses): void
    {
        Process::fake(function ($process) use ($responses) {
            $command = implode(' ', $process->command);

            return $responses[$command] ?? Process::result('', "Unexpected command: {$command}", 1);
        })->preventStrayProcesses();
    }
}