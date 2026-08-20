<?php

namespace Tests\Feature;

use App\Models\Configuration;
use App\Models\Directory;
use App\Models\DirectoryItem;
use App\Models\ShortURL;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_first_registered_user_becomes_an_admin(): void
    {
        $this->post('/register', [
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $user = User::firstWhere('email', 'owner@example.test');

        $this->assertNotNull($user, 'The first registration should succeed on an empty instance.');
        $this->assertTrue($user->isAdmin(), 'The first user must be an administrator.');
    }

    public function test_second_user_cannot_register_while_signup_is_disabled(): void
    {
        User::factory()->create();
        Configuration::set('allow_signup', false);

        $this->post('/register', [
            'name' => 'Intruder',
            'email' => 'intruder@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertNull(User::firstWhere('email', 'intruder@example.test'));
    }

    public function test_api_token_authenticates_bare_and_with_bearer_prefix(): void
    {
        $user = User::factory()->create();
        $token = $user->issueApiToken();
        $user->save();

        $this->assertSame($user->id, User::findByApiToken($token)?->id);
        $this->assertSame($user->id, User::findByApiToken("Bearer $token")?->id);
        $this->assertNull(User::findByApiToken('not-a-real-token'));
    }

    public function test_api_token_is_not_stored_in_plaintext(): void
    {
        $user = User::factory()->create();
        $token = $user->issueApiToken();
        $user->save();

        $row = (array) \DB::table('users')->where('id', $user->id)->first();

        foreach ($row as $column => $value) {
            if (is_string($value)) {
                $this->assertStringNotContainsString(
                    $token,
                    $value,
                    "The raw token must not be readable from column [$column]."
                );
            }
        }

        // It remains recoverable through the application for display purposes.
        $this->assertSame($token, $user->fresh()->api_token);
    }

    public function test_token_upload_works_across_all_endpoints(): void
    {
        $user = User::factory()->create();
        $token = $user->issueApiToken();
        $user->save();

        $this->post('/f', ['file' => UploadedFile::fake()->create('a.txt', 1)], [
            'Authorization' => $token,
        ])->assertCreated();

        $this->post('/p', ['content' => 'hello'], [
            'Authorization' => "Bearer $token",
        ])->assertCreated();

        $this->post('/s', ['url' => 'https://example.com'], [
            'Authorization' => $token,
        ])->assertCreated();
    }

    public function test_anonymous_paste_and_shorturl_respect_the_setting(): void
    {
        Configuration::set('allow_anonymous_upload', false);

        // These two endpoints never consulted the setting at all.
        $this->post('/p', ['content' => 'anon'])->assertStatus(401);
        $this->post('/s', ['url' => 'https://example.com'])->assertStatus(401);
    }

    public function test_shortener_rejects_non_http_schemes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/s', ['url' => 'javascript:alert(1)'], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->actingAs($user)
            ->post('/s', ['url' => 'data:text/html,<script>alert(1)</script>'], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_expired_short_url_does_not_redirect(): void
    {
        $user = User::factory()->create();

        $shortUrl = ShortURL::create([
            'url' => 'https://example.com',
            'short_code' => 'expired123',
            'expires' => now()->subMinute(),
            'user_id' => $user->id,
            'size' => 19,
        ]);

        $this->get("/s/{$shortUrl->short_code}")->assertNotFound();
    }

    public function test_svg_is_never_previewed_inline_in_a_directory(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/d', [
            'name' => 'Assets',
            'files' => [UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
            'paths' => ['x.svg'],
        ], ['Accept' => 'application/json'])->assertCreated();

        $directory = Directory::firstOrFail();
        DirectoryItem::where('directory_id', $directory->id)->update(['mime' => 'image/svg+xml']);

        $response = $this->actingAs($user)->get("/d/{$directory->short_code}/x.svg?preview=1");

        $response->assertOk();
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('Content-Disposition'),
            'SVG must be downloaded, never rendered inline.'
        );
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'target@example.test']);

        $status = 200;

        for ($attempt = 0; $attempt < 15; $attempt++) {
            $status = $this->post('/login', [
                'email' => 'target@example.test',
                'password' => 'wrong-password',
            ])->getStatusCode();

            if ($status === 429) {
                break;
            }
        }

        $this->assertSame(429, $status, 'Repeated failed logins must eventually be throttled.');
    }
}
