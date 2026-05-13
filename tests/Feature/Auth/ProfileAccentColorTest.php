<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileAccentColorTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_their_accent_color(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put(route('updateUser', $user) . '?_back=1', [
            'accent_color' => '#34d399',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('account_info', 'Profile updated.');
        $this->assertSame('#34d399', $user->fresh()->accent_color);
    }

    public function test_user_can_reset_their_accent_color_to_default(): void
    {
        $user = User::factory()->create([
            'accent_color' => '#34d399',
        ]);

        $response = $this->actingAs($user)->put(route('updateUser', $user) . '?_back=1', [
            'accent_color' => '',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('account_info', 'Profile updated.');
        $this->assertNull($user->fresh()->accent_color);
    }

    public function test_invalid_accent_color_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put(route('updateUser', $user) . '?_back=1', [
            'accent_color' => 'javascript:alert(1)',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('accent_color');
        $this->assertNull($user->fresh()->accent_color);
    }

    public function test_saved_accent_color_overrides_theme_variables(): void
    {
        $user = User::factory()->create([
            'accent_color' => '#34d399',
        ]);

        $response = $this->actingAs($user)->get(route('profile'));

        $response->assertOk();
        $response->assertSee('--accent: #34d399;', false);
        $response->assertSee('--accent-rgb: 52, 211, 153;', false);
    }
}