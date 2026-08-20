<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\User;
use App\Support\Thumbnailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ThumbnailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not available in this PHP build.');
        }
    }

    private function uploadImage(User $user, int $width = 1200, int $height = 900): File
    {
        $response = $this->actingAs($user)->post('/f', [
            'file' => UploadedFile::fake()->image('photo.jpg', $width, $height),
        ]);

        $response->assertCreated();
        $this->app['auth']->forgetGuards();

        return File::firstWhere('short_code', $response->json('short_code'));
    }

    public function test_thumbnail_is_much_smaller_than_the_original(): void
    {
        $user = User::factory()->create();
        $file = $this->uploadImage($user);

        $response = $this->get("/f/{$file->short_code}?thumb=1");
        $response->assertOk();

        // A BinaryFileResponse exposes the file it will send rather than a
        // buffered body.
        $thumbnailBytes = (int) $response->baseResponse->getFile()->getSize();
        $originalBytes = $file->size;

        $this->assertGreaterThan(0, $thumbnailBytes);
        $this->assertLessThan(
            $originalBytes,
            $thumbnailBytes,
            'The thumbnail should be smaller than the original it replaces.'
        );
    }

    public function test_thumbnail_is_served_inline_with_long_lived_caching(): void
    {
        $user = User::factory()->create();
        $file = $this->uploadImage($user);

        $response = $this->get("/f/{$file->short_code}?thumb=1");

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
    }

    public function test_thumbnail_request_does_not_redirect(): void
    {
        $user = User::factory()->create();
        $file = $this->uploadImage($user);

        // Listings request many of these; a redirect per image would double the
        // number of round trips.
        $this->get("/f/{$file->short_code}?thumb=1")->assertOk();
    }

    public function test_password_protected_files_have_no_thumbnail(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/f', [
            'file' => UploadedFile::fake()->image('secret.jpg', 800, 600),
            'password' => 'hunter2',
        ]);
        $response->assertCreated();
        $this->app['auth']->forgetGuards();

        $code = $response->json('short_code');

        // Falls through to the normal protected-file flow, which redirects to
        // the named URL and then asks for the password, rather than handing
        // back a derivative of the contents.
        $this->followingRedirects()
            ->get("/f/$code?thumb=1")
            ->assertOk()
            ->assertSee('password', false);

        $this->assertFalse(Thumbnailer::exists($code));
    }

    public function test_non_image_uploads_fall_back_to_the_download(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/f', [
            'file' => UploadedFile::fake()->create('notes.txt', 4, 'text/plain'),
        ]);
        $response->assertCreated();
        $this->app['auth']->forgetGuards();

        // No thumbnail exists, so the request resolves as an ordinary download.
        $this->get("/f/{$response->json('short_code')}?thumb=1")->assertRedirect();
    }

    public function test_thumbnail_is_removed_when_the_file_expires(): void
    {
        $user = User::factory()->create();
        $file = $this->uploadImage($user);

        $this->get("/f/{$file->short_code}?thumb=1")->assertOk();
        $this->assertTrue(Thumbnailer::exists($file->short_code));

        $file->expire();

        $this->assertFalse(
            Thumbnailer::exists($file->short_code),
            'An expired file must not leave its thumbnail behind.'
        );
    }
}
