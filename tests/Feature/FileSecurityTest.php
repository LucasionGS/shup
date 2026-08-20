<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function uploadAs(?User $user, UploadedFile $file, array $extra = []): string
    {
        $request = $user ? $this->actingAs($user) : $this;
        $response = $request->post('/f', array_merge(['file' => $file], $extra));
        $response->assertCreated();

        // actingAs() keeps the user authenticated for every later request on
        // this test case, which would silently turn "anonymous" assertions into
        // owner assertions.
        $this->forgetCurrentUser();

        return $response->json('short_code');
    }

    private function forgetCurrentUser(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_a_stranger_cannot_delete_an_unprotected_file(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $code = $this->uploadAs($owner, UploadedFile::fake()->create('notes.txt', 4));

        $this->actingAs($stranger)
            ->delete("/f/$code")
            ->assertStatus(403);

        $this->assertDatabaseHas('files', ['short_code' => $code]);
    }

    public function test_an_anonymous_visitor_cannot_delete_an_unprotected_file(): void
    {
        $owner = User::factory()->create();
        $code = $this->uploadAs($owner, UploadedFile::fake()->create('notes.txt', 4));

        $this->delete("/f/$code")->assertStatus(403);

        $this->assertDatabaseHas('files', ['short_code' => $code]);
    }

    public function test_owner_can_delete_their_file(): void
    {
        $owner = User::factory()->create();
        $code = $this->uploadAs($owner, UploadedFile::fake()->create('notes.txt', 4));

        $this->actingAs($owner)->delete("/f/$code")->assertNoContent();

        $this->assertDatabaseMissing('files', ['short_code' => $code]);
    }

    public function test_the_state_changing_delete_get_route_is_gone(): void
    {
        $owner = User::factory()->create();
        $code = $this->uploadAs($owner, UploadedFile::fake()->create('notes.txt', 4));

        // Previously <img src=".../delete"> on any page deleted the file.
        $this->actingAs($owner)->get("/f/$code/delete")->assertNotFound();

        $this->assertDatabaseHas('files', ['short_code' => $code]);
    }

    public function test_uploaded_html_is_served_as_a_download_and_not_rendered(): void
    {
        $owner = User::factory()->create();
        $code = $this->uploadAs(
            $owner,
            UploadedFile::fake()->createWithContent('payload.html', '<script>alert(1)</script>')
        );

        $file = File::firstWhere('short_code', $code);

        $response = $this->actingAs($owner)->get("/f/$code/" . $file->original_name);

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString(
            'attachment',
            $response->headers->get('Content-Disposition'),
            'Uploaded HTML must be sent as an attachment, not rendered inline.'
        );
    }

    public function test_download_carries_cache_validators(): void
    {
        $owner = User::factory()->create();
        $code = $this->uploadAs($owner, UploadedFile::fake()->create('image.bin', 8));
        $file = File::firstWhere('short_code', $code);

        $response = $this->actingAs($owner)->get("/f/$code/" . $file->original_name);

        $response->assertOk();
        $this->assertNotNull($response->headers->get('ETag'));
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));
    }

    public function test_password_protected_file_round_trips_through_encryption(): void
    {
        $owner = User::factory()->create();
        $content = str_repeat('secret-payload ', 500);

        $code = $this->uploadAs(
            $owner,
            UploadedFile::fake()->createWithContent('secret.txt', $content),
            ['password' => 'hunter2']
        );

        $file = File::firstWhere('short_code', $code);

        // The stored blob must not be the plaintext.
        $stored = file_get_contents(File::storageDirectory() . '/' . $code);
        $this->assertStringNotContainsString('secret-payload', $stored);

        $response = $this->get("/f/$code/" . rawurlencode($file->original_name) . '?password=hunter2');
        $response->assertOk();
        $this->assertSame($content, $response->streamedContent());
    }

    public function test_expired_files_are_not_served(): void
    {
        $owner = User::factory()->create();
        $code = $this->uploadAs($owner, UploadedFile::fake()->create('old.txt', 4));

        File::where('short_code', $code)->update(['expires' => now()->subMinute()]);

        $this->get("/f/$code")->assertNotFound();
    }

    public function test_upload_is_rejected_when_it_would_exceed_the_quota(): void
    {
        $user = User::factory()->create([
            'storage_limit' => 1024,
            'storage_used' => 900,
        ]);

        $this->actingAs($user)
            ->post('/f', ['file' => UploadedFile::fake()->create('big.bin', 8)]) // 8 KB
            ->assertStatus(413);

        $this->assertDatabaseCount('files', 0);
    }

    public function test_unlimited_quota_still_accepts_uploads(): void
    {
        $user = User::factory()->create([
            'storage_limit' => 0, // 0 means unlimited
            'storage_used' => 10_000_000,
        ]);

        $this->actingAs($user)
            ->post('/f', ['file' => UploadedFile::fake()->create('fine.bin', 8)])
            ->assertCreated();
    }

    public function test_download_limit_retires_the_share(): void
    {
        $owner = User::factory()->create();
        $code = $this->uploadAs(
            $owner,
            UploadedFile::fake()->create('limited.txt', 4),
            ['max_downloads' => 1]
        );

        $file = File::firstWhere('short_code', $code);
        $name = rawurlencode($file->original_name);

        // A stranger's download counts and consumes the single allowance.
        $this->get("/f/$code/$name")->assertOk();
        $this->get("/f/$code/$name")->assertNotFound();
    }

    public function test_short_codes_are_not_predictable_and_are_wide_enough(): void
    {
        $owner = User::factory()->create();
        $codes = [];

        for ($i = 0; $i < 5; $i++) {
            $codes[] = $this->uploadAs($owner, UploadedFile::fake()->create("f$i.txt", 1));
        }

        $this->assertCount(5, array_unique($codes));

        foreach ($codes as $code) {
            $this->assertGreaterThanOrEqual(10, strlen($code));
        }
    }
}
