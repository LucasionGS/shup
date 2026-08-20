<?php

namespace Tests\Feature;

use App\Models\Directory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DirectoryUploadPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * Uploading a folder used to issue roughly 4-6 queries per file: the
     * directory and the owner were re-read and re-saved for every single file,
     * and every path segment was looked up again per file. The work is now
     * batched, so the query count must grow far more slowly than the file count.
     */
    public function test_multi_file_upload_does_not_scale_queries_per_file(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/d', [
            'name' => 'Bulk',
        ], ['Accept' => 'application/json'])->assertCreated();

        $directory = Directory::firstOrFail();

        $files = [];
        $paths = [];
        $fileCount = 30;

        for ($i = 0; $i < $fileCount; $i++) {
            $files[] = UploadedFile::fake()->create("file$i.txt", 1);
            $paths[] = "nested/deep/tree/file$i.txt";
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->actingAs($user)->post("/d/{$directory->short_code}/-/upload", [
            'files' => $files,
            'paths' => $paths,
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertLessThan(
            $fileCount * 3,
            $queries,
            "Uploading $fileCount files took $queries queries, which suggests the per-file work is back."
        );

        $this->assertSame($fileCount, $directory->fresh()->items()->where('type', 'file')->count());
    }

    public function test_concurrent_style_size_updates_do_not_lose_bytes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/d', ['name' => 'Sizes'], [
            'Accept' => 'application/json',
        ])->assertCreated();

        $directory = Directory::firstOrFail();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)->post("/d/{$directory->short_code}/-/upload", [
                'files' => [UploadedFile::fake()->create("f$i.bin", 10)],
                'paths' => ["f$i.bin"],
            ], ['Accept' => 'application/json'])->assertOk();
        }

        $expected = $directory->fresh()->items()->sum('size');

        $this->assertSame((int) $expected, (int) $directory->fresh()->size);
        $this->assertSame((int) $expected, (int) $user->fresh()->storage_used);
    }
}
