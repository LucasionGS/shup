<?php

namespace Tests\Feature;

use App\Mail\PasswordResetLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_the_forgot_password_link_no_longer_errors(): void
    {
        // This route used to render a view that did not exist, so the link on
        // the login page returned a 500.
        $this->get('/login')->assertOk()->assertSee('Forgot your password?');
        $this->get('/forgot-password')->assertOk()->assertSee('Reset Your Password');
    }

    public function test_a_reset_link_is_emailed_to_a_known_address(): void
    {
        $user = User::factory()->create(['email' => 'known@example.test']);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect();

        Mail::assertSent(PasswordResetLink::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_an_unknown_address_looks_identical_but_sends_nothing(): void
    {
        $this->post('/forgot-password', ['email' => 'nobody@example.test'])
            ->assertRedirect()
            ->assertSessionHas('status');

        // Same response either way, so the form cannot be used to discover
        // which addresses have accounts.
        Mail::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_the_raw_token_is_not_stored(): void
    {
        $user = User::factory()->create();
        $token = null;

        $this->post('/forgot-password', ['email' => $user->email]);

        Mail::assertSent(PasswordResetLink::class, function ($mail) use (&$token) {
            $token = $mail;

            return true;
        });

        $stored = DB::table('password_reset_tokens')->where('email', $user->email)->first();

        $this->assertNotNull($stored);
        $this->assertSame(64, strlen($stored->token), 'Only a SHA-256 hash should be stored.');
    }

    public function test_a_valid_token_changes_the_password(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $token = bin2hex(random_bytes(32));

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => hash('sha256', $token),
            'created_at' => now(),
        ]);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('a-brand-new-password', $user->fresh()->password));
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_a_token_cannot_be_used_twice(): void
    {
        $user = User::factory()->create();
        $token = bin2hex(random_bytes(32));

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => hash('sha256', $token),
            'created_at' => now(),
        ]);

        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => 'first-new-password',
            'password_confirmation' => 'first-new-password',
        ];

        $this->post('/reset-password', $payload)->assertRedirect(route('login'));

        $this->post('/reset-password', array_merge($payload, [
            'password' => 'second-attempt-password',
            'password_confirmation' => 'second-attempt-password',
        ]))->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('first-new-password', $user->fresh()->password));
    }

    public function test_an_expired_token_is_refused(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $token = bin2hex(random_bytes(32));

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => hash('sha256', $token),
            'created_at' => now()->subHours(3),
        ]);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'should-not-apply',
            'password_confirmation' => 'should-not-apply',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_a_forged_token_is_refused(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => hash('sha256', bin2hex(random_bytes(32))),
            'created_at' => now(),
        ]);

        $this->post('/reset-password', [
            'token' => 'not-the-real-token',
            'email' => $user->email,
            'password' => 'should-not-apply',
            'password_confirmation' => 'should-not-apply',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }
}
