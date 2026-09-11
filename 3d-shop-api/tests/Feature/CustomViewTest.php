<?php

namespace Tests\Feature;

use App\Jobs\RenderCustomView;
use App\Models\CustomView;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomViewTest extends TestCase
{
    use RefreshDatabase;

    private const CAMERA = [
        'position' => [0, 5, 0.01],
        'target' => [0, 0, 0],
        'fov' => 75,
    ];

    private Product $product;

    private ProductFile $source;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('models');
        Storage::fake('public');

        $this->product = Product::create([
            'name' => 'Delivery truck',
            'price_cents' => 6900,
            'preview_mode' => Product::PREVIEW_ATTESTED_STILLS,
            'user_id' => User::create([
                'name' => 'seller',
                'email' => 'seller@example.com',
                'password' => 'password123',
            ])->id,
        ]);

        $this->source = $this->product->files()->create([
            'kind' => ProductFile::KIND_DELIVERABLE,
            'format' => 'gltf',
            'disk' => 'models',
            'path' => 'gltf_files/truck.glb',
            'bytes' => 100,
            'checksum' => hash('sha256', 'truck'),
            'meta' => ['sniffed_format' => 'glb', 'facts' => ['uvs' => true]],
        ]);

        // A file with no server views of its own has nothing to aim at.
        $this->product->files()->create([
            'source_file_id' => $this->source->id,
            'kind' => ProductFile::KIND_PREVIEW_IMAGE,
            'format' => 'png',
            'disk' => 'public',
            'path' => 'preview_images/a.png',
            'sort' => 0,
            'bytes' => 10,
            'checksum' => hash('sha256', 'a'),
        ]);

        $this->viewer = User::create([
            'name' => 'viewer',
            'email' => 'viewer@example.com',
            'password' => 'password123',
        ]);
    }

    public function test_a_signed_out_visitor_cannot_ask_for_one(): void
    {
        $this->postJson("/api/products/{$this->product->id}/views", $this->payload())
            ->assertStatus(401);

        $this->assertSame(0, CustomView::count());
    }

    public function test_a_viewer_can_ask_for_a_camera_nobody_framed(): void
    {
        Sanctum::actingAs($this->viewer);

        $body = $this->postJson("/api/products/{$this->product->id}/views", $this->payload())
            ->assertSuccessful()
            ->json();

        $this->assertSame(CustomView::QUEUED, $body['status']);
        $this->assertSame('wireframe', $body['pass']);
        $this->assertNull($body['url']);

        $view = CustomView::sole();
        $this->assertSame($this->viewer->id, $view->user_id);
        $this->assertSame($this->source->id, $view->product_file_id);
        Queue::assertPushed(RenderCustomView::class);
    }

    public function test_it_expires_in_two_hours(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->postJson("/api/products/{$this->product->id}/views", $this->payload())
            ->assertSuccessful();

        $this->assertEqualsWithDelta(
            now()->addHours(2)->timestamp,
            CustomView::sole()->expires_at->timestamp,
            60
        );
    }

    /** A render is a container; asking twice for one picture should buy one. */
    public function test_the_same_camera_twice_is_drawn_once(): void
    {
        Sanctum::actingAs($this->viewer);

        $first = $this->postJson("/api/products/{$this->product->id}/views", $this->payload())
            ->assertSuccessful()->json('id');
        $second = $this->postJson("/api/products/{$this->product->id}/views", $this->payload())
            ->assertSuccessful()->json('id');

        $this->assertSame($first, $second);
        $this->assertSame(1, CustomView::count());
    }

    public function test_the_other_pass_of_the_same_camera_is_its_own_view(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->postJson("/api/products/{$this->product->id}/views", $this->payload('wireframe'))
            ->assertSuccessful();
        $this->postJson("/api/products/{$this->product->id}/views", $this->payload('shaded'))
            ->assertSuccessful();

        $this->assertSame(2, CustomView::count());
    }

    public function test_two_viewers_get_their_own(): void
    {
        Sanctum::actingAs($this->viewer);
        $this->postJson("/api/products/{$this->product->id}/views", $this->payload())->assertSuccessful();

        Sanctum::actingAs(User::create([
            'name' => 'other',
            'email' => 'other@example.com',
            'password' => 'password123',
        ]));
        $this->postJson("/api/products/{$this->product->id}/views", $this->payload())->assertSuccessful();

        $this->assertSame(2, CustomView::count());
    }

    public function test_asking_again_after_a_failure_tries_again(): void
    {
        Sanctum::actingAs($this->viewer);
        $id = $this->postJson("/api/products/{$this->product->id}/views", $this->payload())->json('id');
        CustomView::whereKey($id)->update(['status' => CustomView::FAILED, 'failure_reason' => 'nope']);

        $body = $this->postJson("/api/products/{$this->product->id}/views", $this->payload())
            ->assertSuccessful()
            ->json();

        $this->assertSame($id, $body['id']);
        $this->assertSame(CustomView::QUEUED, $body['status']);
        $this->assertNull($body['error']);
    }

    public function test_a_format_the_product_does_not_have_is_refused(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->postJson("/api/products/{$this->product->id}/views", $this->payload('shaded', 'obj'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('format');
    }

    /** Nothing to aim at until the seller's own views exist. */
    public function test_a_file_with_no_server_views_is_refused(): void
    {
        $this->source->stills()->delete();
        Sanctum::actingAs($this->viewer);

        $this->postJson("/api/products/{$this->product->id}/views", $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('format');
    }

    public function test_a_camera_outside_the_scene_is_refused(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->postJson("/api/products/{$this->product->id}/views", [
            'format' => 'gltf',
            'pass' => 'shaded',
            'camera' => ['position' => [0, 99999, 0], 'target' => [0, 0, 0], 'fov' => 75],
        ])->assertStatus(422)->assertJsonValidationErrors('camera.position.1');
    }

    public function test_a_checker_view_can_be_asked_for(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->postJson("/api/products/{$this->product->id}/views", $this->payload('checker'))
            ->assertSuccessful()
            ->assertJsonPath('pass', 'checker');
    }

    public function test_a_normals_view_can_be_asked_for(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->postJson("/api/products/{$this->product->id}/views", $this->payload('normals'))
            ->assertSuccessful()
            ->assertJsonPath('pass', 'normals');
    }

    public function test_a_checker_view_is_refused_when_the_file_has_no_uvs(): void
    {
        $meta = $this->source->meta;
        $meta['facts']['uvs'] = false;
        $this->source->update(['meta' => $meta]);

        Sanctum::actingAs($this->viewer);

        $this->postJson("/api/products/{$this->product->id}/views", $this->payload('checker'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('pass');

        // The other passes do not care about UVs.
        $this->postJson("/api/products/{$this->product->id}/views", $this->payload('normals'))
            ->assertSuccessful();
    }

    public function test_an_unknown_pass_is_refused(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->postJson("/api/products/{$this->product->id}/views", $this->payload('clay'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('pass');
    }

    public function test_the_list_is_only_your_own_and_only_the_living(): void
    {
        Sanctum::actingAs($this->viewer);
        $this->postJson("/api/products/{$this->product->id}/views", $this->payload('wireframe'))->assertSuccessful();
        $stale = $this->postJson("/api/products/{$this->product->id}/views", $this->payload('shaded'))->json('id');
        CustomView::whereKey($stale)->update(['expires_at' => now()->subMinute()]);

        $this->getJson("/api/products/{$this->product->id}/views")
            ->assertSuccessful()
            ->assertJsonCount(1, 'views');
    }

    public function test_pruning_removes_the_expired_row_and_its_image(): void
    {
        Storage::disk('public')->put('custom_views/1/1/abc-shaded.png', 'bytes');

        $view = CustomView::create([
            'user_id' => $this->viewer->id,
            'product_id' => $this->product->id,
            'product_file_id' => $this->source->id,
            'pass' => 'shaded',
            'status' => CustomView::READY,
            'camera' => self::CAMERA,
            'fingerprint' => str_repeat('f', 64),
            'disk' => 'public',
            'path' => 'custom_views/1/1/abc-shaded.png',
            'bytes' => 5,
            'expires_at' => now()->subMinute(),
        ]);

        $this->artisan('views:prune')->assertExitCode(0);

        $this->assertNull($view->fresh());
        Storage::disk('public')->assertMissing('custom_views/1/1/abc-shaded.png');
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        CustomView::create([
            'user_id' => $this->viewer->id,
            'product_id' => $this->product->id,
            'product_file_id' => $this->source->id,
            'pass' => 'shaded',
            'status' => CustomView::READY,
            'camera' => self::CAMERA,
            'fingerprint' => str_repeat('e', 64),
            'expires_at' => now()->subMinute(),
        ]);

        $this->artisan('views:prune', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(1, CustomView::count());
    }

    public function test_a_living_view_survives_pruning(): void
    {
        CustomView::create([
            'user_id' => $this->viewer->id,
            'product_id' => $this->product->id,
            'product_file_id' => $this->source->id,
            'pass' => 'shaded',
            'status' => CustomView::READY,
            'camera' => self::CAMERA,
            'fingerprint' => str_repeat('d', 64),
            'expires_at' => now()->addHour(),
        ]);

        $this->artisan('views:prune')->assertExitCode(0);

        $this->assertSame(1, CustomView::count());
    }

    /** @return array<string, mixed> */
    private function payload(string $pass = 'wireframe', string $format = 'gltf'): array
    {
        return ['format' => $format, 'pass' => $pass, 'camera' => self::CAMERA];
    }
}
