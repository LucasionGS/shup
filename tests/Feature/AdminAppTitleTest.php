<?php

namespace Tests\Feature;

use App\Models\Configuration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAppTitleTest extends TestCase
{
    use RefreshDatabase;

    public function test_layout_defaults_to_shup_title(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('<title>Shup</title>', false);
        $response->assertSee('<span class="header-logo-text">Shup</span>', false);
        $response->assertSee('Powered by Shup');
    }

    public function test_admin_can_change_app_title_from_admin_dashboard(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $this->actingAs($admin)->get(route('admin.users'))
            ->assertOk()
            ->assertSee('name="app_title"', false)
            ->assertSee('value="Shup"', false);

        $this->actingAs($admin)->post(route('configure'), [
            'app_title' => 'Ion Drop',
        ])->assertRedirect();

        $this->assertSame('Ion Drop', Configuration::appTitle());

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('<title>Ion Drop</title>', false)
            ->assertSee('<span class="header-logo-text">Ion Drop</span>', false)
            ->assertSee('Powered by Shup')
            ->assertDontSee('Powered by Ion Drop');
    }

    public function test_non_admin_cannot_update_configuration(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
        ]);

        $this->actingAs($user)->post(route('configure'), [
            'app_title' => 'Not Allowed',
        ])->assertRedirect(route('dashboard'));

        $this->assertSame('Shup', Configuration::appTitle());
    }

    public function test_public_standalone_pages_use_configured_window_title(): void
    {
        Configuration::set('app_title', 'Public Drop');

        $this->get('/ul/not-real')
            ->assertOk()
            ->assertSee('Public Drop</title>', false);
    }
}