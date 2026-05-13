<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\BundleItem;
use App\Models\File;
use App\Models\PasteBin;
use App\Models\ShortURL;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BundleTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_a_bundle_with_owned_resources(): void
    {
        $user = User::factory()->create();
        $file = $this->createFileFor($user, ['short_code' => 'fileaa']);
        $paste = $this->createPasteFor($user, ['short_code' => 'pastea']);
        $shortUrl = $this->createShortUrlFor($user, ['short_code' => 'linkaa']);

        $response = $this->actingAs($user)->post('/b?_back=1', [
            'name' => 'Release packet',
            'description' => 'Build assets and notes',
            'expires' => 60,
            'items' => [
                "file:$file->id",
                "paste:$paste->id",
                "short_url:$shortUrl->id",
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('bundle_url');

        /** @var Bundle $bundle */
        $bundle = Bundle::firstOrFail();

        $this->assertSame('Release packet', $bundle->name);
        $this->assertSame($user->id, $bundle->user_id);
        $this->assertNotNull($bundle->expires);
        $this->assertCount(3, $bundle->items);
    }

    public function test_bundle_dashboard_is_available(): void
    {
        $user = User::factory()->create();
        $this->createFileFor($user, ['original_name' => 'manual.pdf']);

        $response = $this->actingAs($user)->get(route('bundles'));

        $response->assertOk();
        $response->assertSee('Create Bundle');
        $response->assertSee('manual.pdf');
        $response->assertSee('name="items[]"', false);
    }

    public function test_public_bundle_page_lists_available_items(): void
    {
        $user = User::factory()->create();
        $file = $this->createFileFor($user, [
            'short_code' => 'filebb',
            'original_name' => 'screenshot.png',
        ]);
        $bundle = $this->createBundleFor($user, ['short_code' => 'bundle1']);
        $this->createBundleItem($bundle, BundleItem::TYPE_FILE, $file->id);

        $response = $this->get('/b/bundle1');

        $response->assertOk();
        $response->assertSee('screenshot.png');
        $response->assertSee('/f/filebb', false);
    }

    public function test_password_protected_bundle_requires_password(): void
    {
        $user = User::factory()->create();
        $file = $this->createFileFor($user, [
            'short_code' => 'filecc',
            'original_name' => 'secret.txt',
        ]);
        $bundle = $this->createBundleFor($user, [
            'short_code' => 'bundle2',
            'password' => Hash::make('open-sesame'),
        ]);
        $this->createBundleItem($bundle, BundleItem::TYPE_FILE, $file->id);

        $this->get('/b/bundle2')
            ->assertOk()
            ->assertSee('Password Required')
            ->assertDontSee('secret.txt');

        $this->get('/b/bundle2?pwd=open-sesame')
            ->assertOk()
            ->assertSee('secret.txt');
    }

    public function test_user_cannot_bundle_someone_elses_resource(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $file = $this->createFileFor($otherUser);

        $response = $this->actingAs($user)->post('/b?_back=1', [
            'name' => 'Not mine',
            'items' => ["file:$file->id"],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('items');
        $this->assertDatabaseCount('bundles', 0);
    }

    private function createBundleFor(User $user, array $overrides = []): Bundle
    {
        return Bundle::create(array_merge([
            'short_code' => 'bundlea',
            'name' => 'Bundle',
            'description' => null,
            'password' => null,
            'expires' => null,
            'user_id' => $user->id,
        ], $overrides));
    }

    private function createBundleItem(Bundle $bundle, string $type, int $id): BundleItem
    {
        return BundleItem::create([
            'bundle_id' => $bundle->id,
            'resource_type' => $type,
            'resource_id' => $id,
            'position' => 0,
        ]);
    }

    private function createFileFor(User $user, array $overrides = []): File
    {
        return File::create(array_merge([
            'short_code' => 'fileaa',
            'original_name' => 'asset.txt',
            'ext' => 'txt',
            'mime' => 'text/plain',
            'downloads' => 0,
            'password' => null,
            'expires' => null,
            'user_id' => $user->id,
            'size' => 100,
        ], $overrides));
    }

    private function createPasteFor(User $user, array $overrides = []): PasteBin
    {
        return PasteBin::create(array_merge([
            'content' => 'hello',
            'short_code' => 'pastea',
            'password' => null,
            'expires' => null,
            'user_id' => $user->id,
            'size' => 5,
        ], $overrides));
    }

    private function createShortUrlFor(User $user, array $overrides = []): ShortURL
    {
        return ShortURL::create(array_merge([
            'url' => 'https://example.com',
            'short_code' => 'linkaa',
            'hits' => 0,
            'expires' => null,
            'user_id' => $user->id,
            'size' => 19,
        ], $overrides));
    }
}