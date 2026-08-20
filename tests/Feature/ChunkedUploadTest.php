<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChunkedUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function chunkFile(string $bytes, string $name = 'chunk.part'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'chunk');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, 'application/octet-stream', null, true);
    }

    private function openSession(User $user, string $payload, array $extra = []): string
    {
        $response = $this->actingAs($user)->postJson('/f/chunk', array_merge([
            'name' => 'large.bin',
            'size' => strlen($payload),
            'mime' => 'application/octet-stream',
        ], $extra));

        $response->assertCreated();

        return $response->json('token');
    }

    public function test_a_file_sent_in_chunks_is_reassembled_exactly(): void
    {
        $user = User::factory()->create();
        $payload = random_bytes(300000);
        $token = $this->openSession($user, $payload);

        $chunkSize = 100000;
        $offset = 0;

        while ($offset < strlen($payload)) {
            $slice = substr($payload, $offset, $chunkSize);

            $response = $this->actingAs($user)->post("/f/chunk/$token", [
                'offset' => $offset,
                'chunk' => $this->chunkFile($slice),
            ]);

            $response->assertOk();
            $offset += strlen($slice);
            $this->assertSame($offset, $response->json('offset'));
        }

        $completed = $this->actingAs($user)->post("/f/chunk/$token/complete");
        $completed->assertCreated();

        $file = File::firstWhere('short_code', $completed->json('short_code'));

        $this->assertNotNull($file);
        $this->assertSame(strlen($payload), (int) $file->size);
        $this->assertSame(
            $payload,
            file_get_contents($file->absolutePath()),
            'The reassembled file must match the original byte for byte.'
        );
    }

    public function test_an_interrupted_upload_resumes_from_the_reported_offset(): void
    {
        $user = User::factory()->create();
        $payload = random_bytes(200000);
        $token = $this->openSession($user, $payload);

        // First half arrives, then the "connection drops".
        $this->actingAs($user)->post("/f/chunk/$token", [
            'offset' => 0,
            'chunk' => $this->chunkFile(substr($payload, 0, 120000)),
        ])->assertOk();

        // A resuming client asks where to continue from.
        $status = $this->actingAs($user)->getJson("/f/chunk/$token");
        $status->assertOk();
        $this->assertSame(120000, $status->json('offset'));
        $this->assertFalse($status->json('complete'));

        $this->actingAs($user)->post("/f/chunk/$token", [
            'offset' => 120000,
            'chunk' => $this->chunkFile(substr($payload, 120000)),
        ])->assertOk();

        $completed = $this->actingAs($user)->post("/f/chunk/$token/complete");
        $completed->assertCreated();

        $file = File::firstWhere('short_code', $completed->json('short_code'));
        $this->assertSame($payload, file_get_contents($file->absolutePath()));
    }

    public function test_a_replayed_chunk_is_rejected_rather_than_duplicated(): void
    {
        $user = User::factory()->create();
        $payload = random_bytes(100000);
        $token = $this->openSession($user, $payload);

        $first = substr($payload, 0, 50000);

        $this->actingAs($user)->post("/f/chunk/$token", [
            'offset' => 0,
            'chunk' => $this->chunkFile($first),
        ])->assertOk();

        // The same chunk again, e.g. a client retry after a timeout. Appending
        // it blindly would corrupt the file.
        $replay = $this->actingAs($user)->post("/f/chunk/$token", [
            'offset' => 0,
            'chunk' => $this->chunkFile($first),
        ]);

        $replay->assertStatus(409);
        $this->assertSame(50000, $replay->json('expected'));
        $this->assertSame(50000, UploadSession::firstWhere('token', $token)->bytesOnDisk());
    }

    public function test_completing_early_is_refused(): void
    {
        $user = User::factory()->create();
        $payload = random_bytes(100000);
        $token = $this->openSession($user, $payload);

        $this->actingAs($user)->post("/f/chunk/$token", [
            'offset' => 0,
            'chunk' => $this->chunkFile(substr($payload, 0, 40000)),
        ])->assertOk();

        $this->actingAs($user)
            ->post("/f/chunk/$token/complete")
            ->assertStatus(409);

        $this->assertDatabaseCount('files', 0);
    }

    public function test_a_chunk_cannot_exceed_the_declared_size(): void
    {
        $user = User::factory()->create();
        $token = $this->openSession($user, random_bytes(1000));

        $this->actingAs($user)->post("/f/chunk/$token", [
            'offset' => 0,
            'chunk' => $this->chunkFile(random_bytes(5000)),
        ])->assertStatus(422);
    }

    public function test_quota_is_enforced_before_any_bytes_are_sent(): void
    {
        $user = User::factory()->create([
            'storage_limit' => 1024,
            'storage_used' => 0,
        ]);

        $this->actingAs($user)
            ->postJson('/f/chunk', [
                'name' => 'too-big.bin',
                'size' => 5_000_000,
            ])
            ->assertStatus(413);

        $this->assertDatabaseCount('upload_sessions', 0);
    }

    public function test_another_user_cannot_touch_someone_elses_session(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $token = $this->openSession($owner, random_bytes(1000));

        $this->actingAs($stranger)->getJson("/f/chunk/$token")->assertStatus(403);

        $this->actingAs($stranger)->post("/f/chunk/$token", [
            'offset' => 0,
            'chunk' => $this->chunkFile(random_bytes(100)),
        ])->assertStatus(403);
    }

    public function test_a_resumable_upload_can_be_password_protected(): void
    {
        $user = User::factory()->create();
        $payload = random_bytes(50000);
        $token = $this->openSession($user, $payload, ['password' => 'hunter2']);

        $this->actingAs($user)->post("/f/chunk/$token", [
            'offset' => 0,
            'chunk' => $this->chunkFile($payload),
        ])->assertOk();

        $completed = $this->actingAs($user)->post("/f/chunk/$token/complete");
        $completed->assertCreated();

        $file = File::firstWhere('short_code', $completed->json('short_code'));

        $this->assertNotNull($file->password);
        $this->assertNotSame(
            $payload,
            file_get_contents($file->absolutePath()),
            'A protected resumable upload must be encrypted at rest.'
        );

        $response = $this->get("/f/{$file->short_code}/" . rawurlencode($file->original_name) . '?password=hunter2');
        $response->assertOk();
        $this->assertSame($payload, $response->streamedContent());
    }

    public function test_abandoned_sessions_are_swept_up(): void
    {
        $user = User::factory()->create();
        $token = $this->openSession($user, random_bytes(1000));

        $session = UploadSession::firstWhere('token', $token);
        $partial = $session->absolutePath();
        $this->assertFileExists($partial);

        $session->forceFill(['expires_at' => now()->subHour()])->save();

        $this->assertSame(1, UploadSession::deleteExpired());
        $this->assertDatabaseCount('upload_sessions', 0);
        $this->assertFileDoesNotExist($partial);
    }

    public function test_cancelling_removes_the_partial_file(): void
    {
        $user = User::factory()->create();
        $token = $this->openSession($user, random_bytes(1000));
        $partial = UploadSession::firstWhere('token', $token)->absolutePath();

        $this->actingAs($user)->delete("/f/chunk/$token")->assertNoContent();

        $this->assertDatabaseCount('upload_sessions', 0);
        $this->assertFileDoesNotExist($partial);
    }
}
