<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RememberMeTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_remember_me_sets_a_remember_token(): void
    {
        $user = User::factory()->create([
            'email' => 'remember@example.com',
            'remember_token' => null,
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => 'on',
        ]);

        $response->assertRedirect('dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_login_without_remember_me_does_not_set_a_remember_token(): void
    {
        $user = User::factory()->create([
            'email' => 'session-only@example.com',
            'remember_token' => null,
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->fresh()->remember_token);
    }
}