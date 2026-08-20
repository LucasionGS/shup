<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_dashboard_reports_instance_totals(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/f', [
            'file' => UploadedFile::fake()->create('report.bin', 12),
        ])->assertCreated();

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk();
        $response->assertSee('Instance Overview');
        $response->assertSee('Largest Accounts');
        $response->assertSee($admin->name);
    }

    public function test_non_admin_cannot_reach_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertRedirect();
    }

    public function test_orphaned_files_are_detected_and_can_be_pruned(): void
    {
        $admin = $this->admin();

        // A blob with no database row: what a half-failed delete leaves behind.
        Storage::disk('local')->put('files/orphanCODE1', 'dangling bytes');

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('orphanCODE1');

        $this->actingAs($admin)
            ->post('/admin/prune-orphans')
            ->assertRedirect();

        $this->assertFalse(Storage::disk('local')->exists('files/orphanCODE1'));
    }

    public function test_pruning_never_touches_a_file_that_has_a_record(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post('/f', [
            'file' => UploadedFile::fake()->create('keep.bin', 4),
        ]);
        $response->assertCreated();
        $code = $response->json('short_code');

        $this->actingAs($admin)->post('/admin/prune-orphans')->assertRedirect();

        $this->assertTrue(
            Storage::disk('local')->exists("files/$code"),
            'A file with a database record must survive the prune.'
        );
        $this->assertDatabaseHas('files', ['short_code' => $code]);
    }

    public function test_admin_can_delete_a_user_and_their_content(): void
    {
        $admin = $this->admin();
        $victim = User::factory()->create();

        $response = $this->actingAs($victim)->post('/f', [
            'file' => UploadedFile::fake()->create('theirs.bin', 4),
        ]);
        $response->assertCreated();
        $code = $response->json('short_code');
        $this->app['auth']->forgetGuards();

        // This button existed in the console but had no route behind it.
        $this->actingAs($admin)
            ->delete("/user/{$victim->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $victim->id]);
        $this->assertDatabaseMissing('files', ['short_code' => $code]);
        $this->assertFalse(Storage::disk('local')->exists("files/$code"));
    }

    public function test_an_admin_cannot_delete_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->delete("/user/{$admin->id}")->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_the_last_administrator_cannot_be_removed(): void
    {
        $admin = $this->admin();
        $second = $this->admin();

        // Two admins: removing one is fine.
        $this->actingAs($admin)->delete("/user/{$second->id}")->assertNoContent();

        // Now only one remains, and it is the acting user, so it is refused.
        $this->actingAs($admin)->delete("/user/{$admin->id}")->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_a_regular_user_cannot_delete_anyone(): void
    {
        $user = User::factory()->create();
        $victim = User::factory()->create();

        $this->actingAs($user)->delete("/user/{$victim->id}")->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $victim->id]);
    }
}
