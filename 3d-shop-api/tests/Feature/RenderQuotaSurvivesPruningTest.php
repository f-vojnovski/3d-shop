<?php

namespace Tests\Feature;

use App\Models\CustomView;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** A quota counting rows its own pruner deletes resets twelve times a day. */
class RenderQuotaSurvivesPruningTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    private ProductFile $source;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('models');
        Storage::fake('public');

        $this->viewer = User::factory()->create();
        $this->source = $this->aModelOnSale();
    }

    public function test_pruning_frees_the_picture_and_keeps_the_record(): void
    {
        $view = $this->askedFor(hoursAgo: 3);
        Storage::disk('public')->put($view->path, 'a rendered view');

        $this->artisan('views:prune')->assertSuccessful();

        Storage::disk('public')->assertMissing($view->path);
        $this->assertNull(CustomView::find($view->id), 'The view should no longer be served.');
        $this->assertNotNull(CustomView::withTrashed()->find($view->id), 'The record of the ask should remain.');
    }

    public function test_a_days_asks_still_count_after_the_pruner_has_run(): void
    {
        for ($i = 0; $i < CustomView::DAILY_LIMIT; $i++) {
            $this->askedFor(hoursAgo: 3);
        }

        $this->artisan('views:prune')->assertSuccessful();

        $this->assertSame(0, CustomView::count(), 'Every view should have been pruned.');
        $this->assertSame(
            CustomView::DAILY_LIMIT,
            CustomView::withTrashed()
                ->where('user_id', $this->viewer->id)
                ->where('created_at', '>=', now()->subDay())
                ->count(),
            'The day still saw this many asks, whatever the pruner did to the pictures.'
        );
    }

    public function test_asks_older_than_a_day_stop_counting(): void
    {
        $this->askedFor(hoursAgo: 30);

        $this->assertSame(
            0,
            CustomView::withTrashed()
                ->where('user_id', $this->viewer->id)
                ->where('created_at', '>=', now()->subDay())
                ->count()
        );
    }

    private function askedFor(int $hoursAgo): CustomView
    {
        $camera = ['position' => [3, 2, 4], 'target' => [0, 0, 0], 'up' => [0, 1, 0], 'fov' => 75];

        $view = CustomView::create([
            'user_id' => $this->viewer->id,
            'product_id' => $this->source->product_id,
            'product_file_id' => $this->source->id,
            'pass' => CustomView::SHADED,
            'status' => CustomView::READY,
            'camera' => $camera,
            'fingerprint' => hash('sha256', uniqid('', true)),
            'disk' => 'public',
            'path' => 'custom_views/'.uniqid('', true).'.png',
            'bytes' => 1024,
            'expires_at' => now()->subHours($hoursAgo)->addHours(CustomView::LIFETIME_HOURS),
        ]);

        $view->forceFill(['created_at' => now()->subHours($hoursAgo)])->saveQuietly();

        return $view->refresh();
    }

    private function aModelOnSale(): ProductFile
    {
        $product = Product::create([
            'name' => 'Something to look at',
            'price_cents' => 1000,
            'currency' => 'USD',
            'user_id' => User::factory()->create()->id,
            'published_at' => now(),
        ]);

        return ProductFile::create([
            'product_id' => $product->id,
            'kind' => ProductFile::KIND_DELIVERABLE,
            'format' => 'obj',
            'disk' => 'models',
            'path' => 'obj_files/on-sale.obj',
            'sort' => 0,
            'bytes' => 9,
            'checksum' => hash('sha256', "v 0 0 0\n"),
            'meta' => ['faces' => 1, 'sniffed_format' => 'obj'],
        ]);
    }
}
