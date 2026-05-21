<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserQuotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_users_page_includes_quota_control(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        User::factory()->create([
            'storage_limit' => 1024 ** 3,
            'storage_used' => 512,
        ]);

        $this->actingAs($admin)->get(route('admin.users'))
            ->assertOk()
            ->assertSee('name="storage_limit"', false)
            ->assertSee('Use 0, Unlimited, or 10 GB.')
            ->assertSee('1 GB');
    }

    public function test_admin_can_set_user_storage_quota_with_human_readable_value(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);
        $user = User::factory()->create([
            'storage_limit' => 0,
        ]);

        $response = $this->actingAs($admin)->put(route('updateUser', $user) . '?_back=1', [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'storage_limit' => '2 GB',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('account_info', 'Profile updated.');
        $this->assertSame(2 * (1024 ** 3), $user->fresh()->storage_limit);
    }

    public function test_admin_can_make_user_storage_quota_unlimited(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);
        $user = User::factory()->create([
            'storage_limit' => 1024 ** 3,
        ]);

        $response = $this->actingAs($admin)->put(route('updateUser', $user) . '?_back=1', [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'storage_limit' => 'Infinite',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('account_info', 'Profile updated.');
        $this->assertSame(0, $user->fresh()->storage_limit);
    }

    public function test_non_admin_cannot_update_storage_quota(): void
    {
        $user = User::factory()->create([
            'storage_limit' => 1024,
        ]);

        $response = $this->actingAs($user)->put(route('updateUser', $user) . '?_back=1', [
            'storage_limit' => '2 GB',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('account_info', 'Profile updated.');
        $this->assertSame(1024, $user->fresh()->storage_limit);
    }

    public function test_invalid_storage_quota_is_rejected(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);
        $user = User::factory()->create([
            'storage_limit' => 1024,
        ]);

        $response = $this->actingAs($admin)->put(route('updateUser', $user) . '?_back=1', [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'storage_limit' => 'a lot',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('storage_limit');
        $this->assertSame(1024, $user->fresh()->storage_limit);
    }
}