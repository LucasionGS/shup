<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecalculateStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function makeFile(User $user, string $code, string $contents, bool $writeBlob = true): File
    {
        if ($writeBlob) {
            Storage::disk('local')->put("files/$code", $contents);
        }

        return File::create([
            'short_code' => $code,
            'original_name' => "$code.txt",
            'ext' => 'txt',
            'mime' => 'text/plain',
            'user_id' => $user->id,
            'size' => 0,
            'expires' => null,
        ]);
    }

    /**
     * A record whose blob is gone used to abort the whole command at the first
     * occurrence, so every later file, paste, URL and directory was left
     * unprocessed.
     */
    public function test_a_missing_blob_does_not_abort_the_run(): void
    {
        $user = User::factory()->create();

        $this->makeFile($user, 'aaaaaaaaaa', 'first file');
        $this->makeFile($user, 'bbbbbbbbbb', '', writeBlob: false);
        $this->makeFile($user, 'cccccccccc', 'third file is longer');

        Artisan::call('shup:recalculate_physical_storage');

        // The file *after* the gap being sized is the proof the run continued;
        // previously it was never reached. Asserting on command output would
        // not work here, because the command finishes by calling another
        // command, which resets the captured output buffer.
        $this->assertSame(10, (int) File::firstWhere('short_code', 'aaaaaaaaaa')->size);
        $this->assertSame(20, (int) File::firstWhere('short_code', 'cccccccccc')->size);
    }

    public function test_the_missing_record_is_named_in_the_output(): void
    {
        $user = User::factory()->create();
        $this->makeFile($user, 'gonegone12', '', writeBlob: false);

        // Asserted against the live output stream rather than Artisan::output(),
        // which by this point holds the nested command's text.
        $this->artisan('shup:recalculate_physical_storage')
            ->expectsOutputToContain('no file on disk')
            ->expectsOutputToContain('gonegone12')
            ->assertSuccessful();
    }

    public function test_user_totals_are_still_reconciled_afterwards(): void
    {
        $user = User::factory()->create(['storage_used' => 999999]);

        $this->makeFile($user, 'dddddddddd', 'exactly 16 bytes');
        $this->makeFile($user, 'eeeeeeeeee', '', writeBlob: false);

        Artisan::call('shup:recalculate_physical_storage');

        // The totals pass runs after the file loop, so an abort there would
        // have left storage_used at its stale value.
        $this->assertSame(16, (int) $user->fresh()->storage_used);
    }

    public function test_a_complete_instance_reports_no_missing_files(): void
    {
        $user = User::factory()->create();
        $this->makeFile($user, 'ffffffffff', 'all present');

        Artisan::call('shup:recalculate_physical_storage');

        $this->assertStringNotContainsString('no file on disk', Artisan::output());
    }
}
