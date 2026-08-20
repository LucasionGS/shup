<?php

namespace Tests\Feature;

use App\Models\Directory;
use App\Models\PasteBin;
use App\Models\ShortURL;
use App\Models\UploadLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * These listings previously loaded every row the user owned (and every account
 * on the instance, in the admin case), so page cost grew without bound.
 */
class DashboardPaginationTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE_SIZE = 15;

    private function seedFor(User $user, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            PasteBin::create([
                'content' => "paste $i",
                'short_code' => "paste$i",
                'expires' => null,
                'user_id' => $user->id,
                'size' => 7,
            ]);

            ShortURL::create([
                'url' => "https://example.com/$i",
                'short_code' => "url$i",
                'expires' => null,
                'user_id' => $user->id,
                'size' => 20,
            ]);

            Directory::create([
                'short_code' => "dir$i",
                'name' => "Directory $i",
                'expires' => null,
                'user_id' => $user->id,
                'size' => 0,
            ]);

            UploadLink::create([
                'short_code' => "link$i",
                'user_id' => $user->id,
                'used' => false,
                'expires' => null,
            ]);
        }
    }

    /**
     * Counts how many of the seeded records appear in the rendered page.
     * Rows are matched by their short code prefix rather than by identity,
     * because the fixtures share a created_at second and their relative order
     * is therefore not stable.
     */
    private function renderedRowCount(string $html, string $prefix, int $seeded): int
    {
        $found = 0;

        for ($i = 0; $i < $seeded; $i++) {
            if (str_contains($html, ">$prefix$i<")) {
                $found++;
            }
        }

        return $found;
    }

    public function test_listings_render_at_most_one_page_of_rows(): void
    {
        $user = User::factory()->create();
        $this->seedFor($user, 20);

        $response = $this->actingAs($user)->get('/dashboard/pastes');
        $response->assertOk();
        $response->assertSee('Next');

        $this->assertSame(
            self::PAGE_SIZE,
            $this->renderedRowCount($response->getContent(), 'paste', 20),
            'Exactly one page of rows should be rendered.'
        );
    }

    public function test_the_other_listings_are_paged_too(): void
    {
        $user = User::factory()->create();
        $this->seedFor($user, 20);

        foreach (['/dashboard/shorturls', '/dashboard/directories', '/dashboard/uploadlinks'] as $url) {
            $this->actingAs($user)->get($url)->assertOk()->assertSee('Next');
        }
    }

    public function test_paging_covers_every_row_across_both_pages(): void
    {
        $user = User::factory()->create();
        $this->seedFor($user, 20);

        $firstPage = $this->actingAs($user)->get('/dashboard/pastes');
        $secondPage = $this->actingAs($user)->get('/dashboard/pastes?page=1');

        $secondPage->assertOk()->assertSee('Previous');

        $total = $this->renderedRowCount($firstPage->getContent(), 'paste', 20)
            + $this->renderedRowCount($secondPage->getContent(), 'paste', 20);

        $this->assertSame(20, $total, 'Paging must not drop or duplicate rows.');
    }

    public function test_listing_query_is_bounded_regardless_of_row_count(): void
    {
        $user = User::factory()->create();
        $this->seedFor($user, 60);

        $rowsRead = 0;
        DB::listen(function ($query) use (&$rowsRead) {
            if (str_contains($query->sql, 'from "paste_bins"') && str_contains($query->sql, 'limit')) {
                $rowsRead++;
            }
        });

        $this->actingAs($user)->get('/dashboard/pastes')->assertOk();

        $this->assertGreaterThan(0, $rowsRead, 'The listing query should apply a limit.');
    }

    public function test_admin_user_listing_is_paged(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        User::factory()->count(30)->create();

        $response = $this->actingAs($admin)->get('/admin/users');

        $response->assertOk();
        $response->assertSee('Next');
    }
}
