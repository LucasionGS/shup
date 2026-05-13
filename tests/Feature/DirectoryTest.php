<?php

namespace Tests\Feature;

use App\Models\Directory as ShupDirectory;
use App\Models\DirectoryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_user_can_create_an_empty_directory(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/d', [
            'name' => 'Project files',
            'description' => 'Release assets',
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertCreated();

        /** @var ShupDirectory $directory */
        $directory = ShupDirectory::firstOrFail();

        $this->assertSame('Project files', $directory->name);
        $this->assertSame($user->id, $directory->user_id);
        $this->assertSame(0, $directory->size);

        $this->get("/d/$directory->short_code")
            ->assertOk()
            ->assertSee('Project files')
            ->assertSee('This folder is empty.');
    }

    public function test_user_can_upload_and_browse_nested_directory_files(): void
    {
        $user = User::factory()->create();
        $directory = $this->createDirectoryFor($user);

        $response = $this->actingAs($user)->post("/d/$directory->short_code/-/upload", [
            'files' => [
                UploadedFile::fake()->createWithContent('readme.txt', 'hello docs'),
                UploadedFile::fake()->createWithContent('logo.txt', 'image bytes'),
            ],
            'paths' => [
                'docs/readme.txt',
                'docs/assets/logo.txt',
            ],
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertOk()->assertJsonPath('uploaded', 2);

        $this->assertDatabaseHas('directory_items', [
            'directory_id' => $directory->id,
            'type' => DirectoryItem::TYPE_FOLDER,
            'path' => 'docs',
        ]);
        $this->assertDatabaseHas('directory_items', [
            'directory_id' => $directory->id,
            'type' => DirectoryItem::TYPE_FOLDER,
            'path' => 'docs/assets',
        ]);
        $this->assertDatabaseHas('directory_items', [
            'directory_id' => $directory->id,
            'type' => DirectoryItem::TYPE_FILE,
            'path' => 'docs/readme.txt',
        ]);

        $this->get("/d/$directory->short_code/docs")
            ->assertOk()
            ->assertSee('assets')
            ->assertSee('readme.txt');
    }

    public function test_directory_file_path_downloads_individual_file(): void
    {
        $user = User::factory()->create();
        $directory = $this->createDirectoryFor($user);
        $this->uploadFileToDirectory($user, $directory, 'docs/readme.txt', 'hello docs');

        $response = $this->get("/d/$directory->short_code/docs/readme.txt");

        $response->assertOk();
        $this->assertStringContainsString('readme.txt', $response->headers->get('content-disposition'));
    }

    public function test_directory_media_files_are_previewed_inline(): void
    {
        $user = User::factory()->create();
        $directory = $this->createDirectoryFor($user);

        $this->actingAs($user)->post("/d/$directory->short_code/-/upload", [
            'files' => [
                UploadedFile::fake()->create('photo.jpg', 1, 'image/jpeg'),
                UploadedFile::fake()->create('sound.mp3', 1, 'audio/mpeg'),
                UploadedFile::fake()->create('clip.mp4', 1, 'video/mp4'),
            ],
            'paths' => [
                'photo.jpg',
                'sound.mp3',
                'clip.mp4',
            ],
        ], [
            'Accept' => 'application/json',
        ])->assertOk();

        $this->get("/d/$directory->short_code")
            ->assertOk()
            ->assertSee('<img src="', false)
            ->assertSee('<audio src="', false)
            ->assertSee('<video src="', false)
            ->assertSee('preview=1', false);

        $preview = $this->get("/d/$directory->short_code/photo.jpg?preview=1");

        $preview->assertOk();
        $this->assertStringContainsString('image/', $preview->headers->get('content-type'));
        $this->assertStringNotContainsString('attachment', $preview->headers->get('content-disposition') ?? '');
    }

    public function test_directory_upload_rejects_traversal_paths(): void
    {
        $user = User::factory()->create();
        $directory = $this->createDirectoryFor($user);

        $response = $this->actingAs($user)->post("/d/$directory->short_code/-/upload", [
            'files' => [UploadedFile::fake()->createWithContent('secret.txt', 'secret')],
            'paths' => ['../secret.txt'],
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('directory_items', [
            'directory_id' => $directory->id,
            'path' => '../secret.txt',
        ]);
    }

    public function test_only_owner_can_edit_directory_entries(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $directory = $this->createDirectoryFor($owner);

        $response = $this->actingAs($otherUser)->post("/d/$directory->short_code/-/folders", [
            'name' => 'private',
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('directory_items', [
            'directory_id' => $directory->id,
            'path' => 'private',
        ]);
    }

    public function test_deleting_file_updates_directory_and_user_storage(): void
    {
        $user = User::factory()->create();
        $directory = $this->createDirectoryFor($user);
        $this->uploadFileToDirectory($user, $directory, 'docs/readme.txt', 'hello');

        $this->assertSame(5, $directory->fresh()->size);
        $this->assertSame(5, $user->fresh()->storage_used);

        $response = $this->actingAs($user)->delete("/d/$directory->short_code/-/entries", [
            'path' => 'docs/readme.txt',
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(204);
        $this->assertSame(0, $directory->fresh()->size);
        $this->assertSame(0, $user->fresh()->storage_used);
        $this->assertDatabaseMissing('directory_items', [
            'directory_id' => $directory->id,
            'path' => 'docs/readme.txt',
        ]);
    }

    public function test_directory_zip_is_streamed_with_nested_paths(): void
    {
        $user = User::factory()->create();
        $directory = $this->createDirectoryFor($user);
        $this->uploadFileToDirectory($user, $directory, 'docs/readme.txt', 'hello docs');
        $this->uploadFileToDirectory($user, $directory, 'docs/assets/logo.txt', 'logo bytes');

        $response = $this->get("/d/$directory->short_code/-/zip/docs");

        $response->assertOk();
        $this->assertStringContainsString('application/zip', $response->headers->get('content-type'));

        ob_start();
        $response->baseResponse->sendContent();
        $content = ob_get_clean();

        $this->assertStringStartsWith("PK\x03\x04", $content);
        $this->assertStringContainsString('readme.txt', $content);
        $this->assertStringContainsString('assets/logo.txt', $content);
    }

    private function createDirectoryFor(User $user, array $overrides = []): ShupDirectory
    {
        return ShupDirectory::create(array_merge([
            'short_code' => 'dir001',
            'name' => 'Directory',
            'description' => null,
            'password' => null,
            'expires' => null,
            'user_id' => $user->id,
            'size' => 0,
        ], $overrides));
    }

    private function uploadFileToDirectory(User $user, ShupDirectory $directory, string $path, string $content): void
    {
        $this->actingAs($user)->post("/d/$directory->short_code/-/upload", [
            'files' => [UploadedFile::fake()->createWithContent(basename($path), $content)],
            'paths' => [$path],
        ], [
            'Accept' => 'application/json',
        ])->assertOk();
    }
}