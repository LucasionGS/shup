<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FilePreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * A real 1x1 PNG, so these tests do not depend on GD being available.
     */
    private function pngUpload(string $name = 'photo.png'): UploadedFile
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        return UploadedFile::fake()->createWithContent($name, $png);
    }

    private function upload(User $user, UploadedFile $file): File
    {
        $response = $this->actingAs($user)->post('/f', ['file' => $file]);
        $response->assertCreated();
        $this->app['auth']->forgetGuards();

        return File::firstWhere('short_code', $response->json('short_code'));
    }

    public function test_a_text_file_is_rendered_on_the_preview_page(): void
    {
        $user = User::factory()->create();
        $file = $this->upload(
            $user,
            UploadedFile::fake()->createWithContent('notes.txt', "line one\nline two")
        );

        $response = $this->followingRedirects()->get("/f/{$file->short_code}?view=1");

        $response->assertOk();
        $response->assertSee('notes.txt');
        $response->assertSee('line two');
    }

    public function test_text_contents_are_escaped_not_executed(): void
    {
        $user = User::factory()->create();
        $file = $this->upload(
            $user,
            UploadedFile::fake()->createWithContent('payload.txt', '<script>alert(1)</script>')
        );

        $response = $this->followingRedirects()->get("/f/{$file->short_code}?view=1");

        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;', false);
    }

    public function test_an_html_upload_is_never_offered_as_an_inline_preview(): void
    {
        $user = User::factory()->create();
        $file = $this->upload(
            $user,
            UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>')
        );

        // Even asked for explicitly, a scriptable document stays an attachment.
        $response = $this->get(
            "/f/{$file->short_code}/" . rawurlencode($file->original_name) . '?inline=1'
        );

        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('Content-Disposition')
        );
    }

    public function test_an_svg_upload_is_never_offered_as_an_inline_preview(): void
    {
        $user = User::factory()->create();
        $file = $this->upload(
            $user,
            UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')
        );

        File::where('id', $file->id)->update(['mime' => 'image/svg+xml']);

        $response = $this->get(
            "/f/{$file->short_code}/" . rawurlencode($file->original_name) . '?inline=1'
        );

        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('Content-Disposition')
        );
    }

    public function test_an_image_may_be_served_inline_for_the_preview(): void
    {
        $user = User::factory()->create();
        $file = $this->upload($user, $this->pngUpload());

        $response = $this->get(
            "/f/{$file->short_code}/" . rawurlencode($file->original_name) . '?inline=1'
        );

        $response->assertOk();
        $this->assertStringContainsString(
            'inline',
            (string) $response->headers->get('Content-Disposition')
        );
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_plain_download_is_unchanged_by_the_preview_feature(): void
    {
        $user = User::factory()->create();
        $file = $this->upload($user, $this->pngUpload());

        // Existing links, ShareX embeds and the CLI must keep getting a
        // download rather than an HTML page.
        $response = $this->get("/f/{$file->short_code}/" . rawurlencode($file->original_name));

        $response->assertOk();
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('Content-Disposition')
        );
    }

    public function test_preview_does_not_count_as_a_download(): void
    {
        $user = User::factory()->create();
        $file = $this->upload($user, UploadedFile::fake()->createWithContent('notes.txt', 'hello'));

        $this->followingRedirects()->get("/f/{$file->short_code}?view=1")->assertOk();

        $this->assertSame(0, (int) $file->fresh()->downloads);
    }
}
