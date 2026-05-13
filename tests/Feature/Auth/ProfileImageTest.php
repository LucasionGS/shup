<?php

namespace Tests\Feature\Auth;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_set_profile_image_to_an_owned_image_upload(): void
    {
        $user = User::factory()->create();
        $file = $this->createFileFor($user, [
            'short_code' => 'avatar123',
            'mime' => 'image/png',
        ]);

        $response = $this->actingAs($user)->post(route('updateUserImage'), [
            'short_code' => $file->short_code,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('account_info', 'Profile image updated.');
        $this->assertSame('/f/avatar123', $user->fresh()->image);
    }

    public function test_user_cannot_set_profile_image_to_an_external_url(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('updateUserImage'), [
            'url' => 'https://example.com/avatar.png',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('image');
        $this->assertNull($user->fresh()->image);
    }

    public function test_user_cannot_set_profile_image_to_someone_elses_upload(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $file = $this->createFileFor($otherUser, [
            'short_code' => 'other-avatar',
            'mime' => 'image/jpeg',
        ]);

        $response = $this->actingAs($user)->post(route('updateUserImage'), [
            'short_code' => $file->short_code,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('image');
        $this->assertNull($user->fresh()->image);
    }

    public function test_user_cannot_set_profile_image_to_a_non_image_upload(): void
    {
        $user = User::factory()->create();
        $file = $this->createFileFor($user, [
            'short_code' => 'document123',
            'mime' => 'application/pdf',
        ]);

        $response = $this->actingAs($user)->post(route('updateUserImage'), [
            'short_code' => $file->short_code,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('image');
        $this->assertNull($user->fresh()->image);
    }

    public function test_user_cannot_set_profile_image_to_a_protected_image_upload(): void
    {
        $user = User::factory()->create();
        $file = $this->createFileFor($user, [
            'short_code' => 'private-avatar',
            'mime' => 'image/png',
            'password' => 'hashed-password',
        ]);

        $response = $this->actingAs($user)->post(route('updateUserImage'), [
            'short_code' => $file->short_code,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('image');
        $this->assertNull($user->fresh()->image);
    }

    public function test_user_cannot_set_profile_image_to_an_expired_image_upload(): void
    {
        $user = User::factory()->create();
        $file = $this->createFileFor($user, [
            'short_code' => 'expired-avatar',
            'mime' => 'image/png',
            'expires' => now()->subMinute(),
        ]);

        $response = $this->actingAs($user)->post(route('updateUserImage'), [
            'short_code' => $file->short_code,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('image');
        $this->assertNull($user->fresh()->image);
    }

    private function createFileFor(User $user, array $overrides = []): File
    {
        return File::create(array_merge([
            'short_code' => 'file123',
            'original_name' => 'avatar.png',
            'ext' => 'png',
            'mime' => 'image/png',
            'downloads' => 0,
            'password' => null,
            'expires' => null,
            'user_id' => $user->id,
            'size' => 100,
        ], $overrides));
    }
}