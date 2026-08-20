<?php

namespace Tests\Feature;

use App\Models\Directory as ShupDirectory;
use App\Models\DirectoryItem;
use App\Models\File as ShupFile;
use App\Models\UploadLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadLinkMultiFileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Applied to every test here rather than to some of them: the
        // single-file cases wrote real blobs into storage/app/private.
        Storage::fake('local');
    }

    public function test_user_can_create_a_multi_file_upload_link(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/ul', [
            'multi_file' => 1,
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertCreated()->assertJson(['multi_file' => true]);

        /** @var UploadLink $link */
        $link = UploadLink::firstOrFail();
        $this->assertTrue($link->multi_file);
    }

    public function test_upload_link_defaults_to_single_file(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/ul', [], ['Accept' => 'application/json'])->assertCreated();

        /** @var UploadLink $link */
        $link = UploadLink::firstOrFail();
        $this->assertFalse($link->multi_file);
    }

    public function test_multiple_files_become_a_directory(): void
    {
        $user = User::factory()->create();
        $link = UploadLink::create([
            'short_code' => 'mlink1',
            'user_id' => $user->id,
            'multi_file' => true,
        ]);

        $response = $this->post("/ul/$link->short_code", [
            'files' => [
                UploadedFile::fake()->createWithContent('readme.txt', 'hello docs'),
                UploadedFile::fake()->createWithContent('logo.png', 'image bytes'),
            ],
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertCreated();

        /** @var ShupDirectory $directory */
        $directory = ShupDirectory::firstOrFail();

        $this->assertSame($user->id, $directory->user_id);
        $this->assertSame(2, $directory->items()->count());
        $this->assertNotNull($directory->items()->where('path', 'readme.txt')->first());
        $this->assertNotNull($directory->items()->where('path', 'logo.png')->first());
        $this->assertSame((int) $directory->items()->sum('size'), $directory->size);
        $this->assertSame($directory->size, $user->fresh()->storage_used);

        $response->assertJson(['url' => url("/d/$directory->short_code")]);

        $this->assertTrue($link->fresh()->used);
        $this->assertSame(0, ShupFile::count());
    }

    public function test_single_file_through_multi_link_stays_a_file(): void
    {
        $user = User::factory()->create();
        $link = UploadLink::create([
            'short_code' => 'mlink2',
            'user_id' => $user->id,
            'multi_file' => true,
        ]);

        $response = $this->post("/ul/$link->short_code", [
            'files' => [
                UploadedFile::fake()->createWithContent('notes.txt', 'just one'),
            ],
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertCreated();

        $this->assertSame(0, ShupDirectory::count());
        $this->assertSame(1, ShupFile::count());
        $this->assertSame('notes.txt', ShupFile::firstOrFail()->original_name);
        $this->assertTrue($link->fresh()->used);
    }

    public function test_duplicate_names_are_made_unique(): void
    {
        $user = User::factory()->create();
        $link = UploadLink::create([
            'short_code' => 'mlink3',
            'user_id' => $user->id,
            'multi_file' => true,
        ]);

        $this->post("/ul/$link->short_code", [
            'files' => [
                UploadedFile::fake()->createWithContent('data.csv', 'first'),
                UploadedFile::fake()->createWithContent('data.csv', 'second'),
            ],
        ], [
            'Accept' => 'application/json',
        ])->assertCreated();

        $paths = DirectoryItem::pluck('path')->sort()->values()->all();
        $this->assertSame(['data (1).csv', 'data.csv'], $paths);
    }

    public function test_single_file_link_still_accepts_a_file(): void
    {
        $user = User::factory()->create();
        $link = UploadLink::create([
            'short_code' => 'slink1',
            'user_id' => $user->id,
        ]);

        $response = $this->post("/ul/$link->short_code", [
            'file' => UploadedFile::fake()->createWithContent('classic.txt', 'old flow'),
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertCreated();
        $this->assertSame(1, ShupFile::count());
        $this->assertTrue($link->fresh()->used);
    }

    public function test_password_protects_the_created_directory(): void
    {
        $user = User::factory()->create();
        $link = UploadLink::create([
            'short_code' => 'mlink4',
            'user_id' => $user->id,
            'multi_file' => true,
        ]);

        $this->post("/ul/$link->short_code", [
            'files' => [
                UploadedFile::fake()->createWithContent('a.txt', 'aaa'),
                UploadedFile::fake()->createWithContent('b.txt', 'bbb'),
            ],
            'password' => 'secret',
        ], [
            'Accept' => 'application/json',
        ])->assertCreated();

        /** @var ShupDirectory $directory */
        $directory = ShupDirectory::firstOrFail();
        $this->assertNotNull($directory->password);

        $this->get("/d/$directory->short_code")
            ->assertOk()
            ->assertSee('password');
    }
}
