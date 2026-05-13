<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FileShareControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_file_upload_form_exposes_share_controls(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('files'));

        $response->assertOk();
        $response->assertSee('name="expires"', false);
        $response->assertSee('name="password"', false);
        $response->assertSee('30 days');
    }

    public function test_user_can_upload_a_password_protected_expiring_file(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/f', [
            'file' => UploadedFile::fake()->create('secret.txt', 1, 'text/plain'),
            'password' => 'swordfish',
            'expires' => 60,
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertCreated();

        /** @var File $file */
        $file = File::firstOrFail();

        $this->assertSame('secret.txt', $file->original_name);
        $this->assertSame('text/plain', $file->mime);
        $this->assertNotNull($file->password);
        $this->assertTrue(Hash::check('swordfish', $file->password));
        $this->assertNotNull($file->expires);
        $this->assertTrue(Carbon::parse($file->expires)->isFuture());
        $this->assertGreaterThan(0, $user->fresh()->storage_used);

        $download = $this->get("/f/{$file->short_code}/{$file->original_name}?pwd=swordfish");

        $download->assertOk();

        $this->deleteStoredFile($file);
    }

    private function deleteStoredFile(File $file): void
    {
        $path = storage_path("app/private/files/$file->short_code");

        if (is_file($path)) {
            unlink($path);
        }
    }
}