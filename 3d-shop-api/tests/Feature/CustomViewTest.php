<?php

namespace Tests\Feature;

use App\Jobs\RenderCustomClip;
use App\Jobs\RenderCustomView;
use App\Jobs\RenderProductPreviews;
use App\Models\CustomView;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\RenderRun;
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

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('models');
        Storage::fake('public');

        $this->seller = User::create([
            'name' => 'seller',
            'email' => 'seller@example.com',
            'password' => 'password123',
        ]);

        $this->product = Product::create([
            'name' => 'Delivery truck',
            'price_cents' => 6900,
            'preview_mode' => Product::PREVIEW_ATTESTED_STILLS,
            'user_id' => $this->seller->id,
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

    public function test_pruning_removes_the_expired_image_and_retires_the_row(): void
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

        Storage::disk('public')->assertMissing('custom_views/1/1/abc-shaded.png');

        $this->assertNull(CustomView::find($view->id));
        $this->assertNotNull(CustomView::withTrashed()->find($view->id), 'The quota counts this row.');
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

    public function test_the_owner_can_put_a_view_they_asked_for_on_the_listing(): void
    {
        Sanctum::actingAs($this->seller);

        $id = $this->postJson("/api/products/{$this->product->id}/views", $this->payload())->json('id');
        CustomView::whereKey($id)->update(['status' => CustomView::READY, 'path' => 'custom_views/a.png', 'disk' => 'public']);

        $this->postJson("/api/products/{$this->product->id}/views/{$id}/publish")->assertSuccessful();

        $angles = $this->source->fresh()->angles();
        $this->assertCount(1, $angles);
        $this->assertSame('requested', $angles[0]['origin']);
        $this->assertSame(self::CAMERA['position'], $angles[0]['position']);

        // The camera travels, not the picture, so the format renders again.
        Queue::assertPushed(RenderProductPreviews::class);
    }

    public function test_a_buyer_cannot_put_a_view_on_someone_elses_listing(): void
    {
        Sanctum::actingAs($this->viewer);

        $id = $this->postJson("/api/products/{$this->product->id}/views", $this->payload())->json('id');
        CustomView::whereKey($id)->update(['status' => CustomView::READY]);

        $this->postJson("/api/products/{$this->product->id}/views/{$id}/publish")->assertForbidden();
        $this->assertSame([], $this->source->fresh()->angles());
    }

    public function test_a_view_that_has_not_been_drawn_cannot_be_published(): void
    {
        Sanctum::actingAs($this->seller);

        $id = $this->postJson("/api/products/{$this->product->id}/views", $this->payload())->json('id');

        $this->postJson("/api/products/{$this->product->id}/views/{$id}/publish")
            ->assertStatus(422)
            ->assertJsonValidationErrors('view');
    }

    public function test_the_same_camera_is_not_added_twice(): void
    {
        Sanctum::actingAs($this->seller);

        $id = $this->postJson("/api/products/{$this->product->id}/views", $this->payload())->json('id');
        CustomView::whereKey($id)->update(['status' => CustomView::READY]);

        $this->postJson("/api/products/{$this->product->id}/views/{$id}/publish")->assertSuccessful();
        $this->postJson("/api/products/{$this->product->id}/views/{$id}/publish")
            ->assertStatus(422)
            ->assertJsonValidationErrors('view');

        $this->assertCount(1, $this->source->fresh()->angles());
    }

    public function test_a_viewer_can_ask_to_see_the_model_moving(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->animated();

        $this->postJson($this->route(), $this->payload('shaded') + ['clip' => 1])
            ->assertSuccessful()
            ->assertJsonPath('clip', 1);

        Queue::assertPushed(RenderCustomClip::class);
        Queue::assertNotPushed(RenderCustomView::class);
    }

    /** The still path draws attested images; a moving one must not go near it. */
    public function test_a_still_still_goes_to_the_still_renderer(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->postJson($this->route(), $this->payload('wireframe'))->assertSuccessful();

        Queue::assertPushed(RenderCustomView::class);
        Queue::assertNotPushed(RenderCustomClip::class);
    }

    public function test_the_same_camera_on_a_different_clip_is_its_own_view(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->animated();

        $this->postJson($this->route(), $this->payload('shaded') + ['clip' => 0])->assertSuccessful();
        $this->postJson($this->route(), $this->payload('shaded') + ['clip' => 1])->assertSuccessful();

        $this->assertSame(2, CustomView::where('product_file_id', $this->source->id)->count());
    }

    public function test_asking_to_see_a_model_move_that_does_not_is_refused(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->postJson($this->route(), $this->payload('shaded') + ['clip' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('clip');

        Queue::assertNotPushed(RenderCustomClip::class);
    }

    /** A wireframe of a walking model is unreadable, so it is not offered. */
    public function test_a_still_only_pass_is_refused_for_a_moving_view(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->animated();

        $this->postJson($this->route(), $this->payload('wireframe') + ['clip' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pass');
    }

    public function test_a_moving_only_pass_is_refused_for_a_still(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->postJson($this->route(), $this->payload('bones'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('pass');
    }

    /**
     * Each of these starts a container that can run for minutes, and the camera
     * is ten free-floating numbers, so the fingerprint stops nobody asking for
     * a thousand almost-identical views.
     */
    public function test_one_account_cannot_queue_renders_without_end(): void
    {
        Sanctum::actingAs($this->viewer);

        for ($made = 0; $made < CustomView::DAILY_LIMIT; $made++) {
            CustomView::create([
                'user_id' => $this->viewer->id,
                'product_id' => $this->product->id,
                'product_file_id' => $this->source->id,
                'pass' => 'shaded',
                'status' => CustomView::QUEUED,
                'camera' => self::CAMERA,
                'fingerprint' => hash('sha256', (string) $made),
                'expires_at' => now()->addHours(CustomView::LIFETIME_HOURS),
            ]);
        }

        $this->postJson($this->route(), $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('camera');

        Queue::assertNotPushed(RenderCustomView::class);
    }

    /**
     * The hole a count alone leaves: few asks, all of them expensive. Ten of the
     * heaviest model cost what sixty of a light one would, and the count is
     * nowhere near its limit.
     */
    public function test_a_few_expensive_renders_are_stopped_as_surely_as_many_cheap_ones(): void
    {
        Sanctum::actingAs($this->viewer);

        RenderRun::create([
            'kind' => 'render',
            'scratch' => 'custom-views/1',
            'user_id' => $this->viewer->id,
            'asked_at' => now(),
            'vcpu_seconds' => CustomView::DAILY_BUDGET_VCPU_SECONDS,
            'status' => 'ran',
        ]);

        $this->assertSame(0, CustomView::count(), 'the count limit is nowhere near reached');

        $this->postJson($this->route(), $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('camera');

        Queue::assertNotPushed(RenderCustomView::class);
    }

    public function test_machine_time_spent_yesterday_does_not_count_against_today(): void
    {
        Sanctum::actingAs($this->viewer);

        $spent = RenderRun::create([
            'kind' => 'render',
            'scratch' => 'custom-views/1',
            'user_id' => $this->viewer->id,
            'asked_at' => now(),
            'vcpu_seconds' => CustomView::DAILY_BUDGET_VCPU_SECONDS,
            'status' => 'ran',
        ]);

        $spent->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->postJson($this->route(), $this->payload())->assertSuccessful();
    }

    /** Yesterday's asking is not today's. */
    public function test_the_limit_is_a_day_long_and_then_lets_go(): void
    {
        Sanctum::actingAs($this->viewer);

        for ($made = 0; $made < CustomView::DAILY_LIMIT; $made++) {
            CustomView::create([
                'user_id' => $this->viewer->id,
                'product_id' => $this->product->id,
                'product_file_id' => $this->source->id,
                'pass' => 'shaded',
                'status' => CustomView::QUEUED,
                'camera' => self::CAMERA,
                'fingerprint' => hash('sha256', (string) $made),
                'expires_at' => now()->addHours(CustomView::LIFETIME_HOURS),
            ]);
        }

        // Eloquent stamps created_at itself, so it is moved afterwards.
        CustomView::query()->update(['created_at' => now()->subDays(2)]);

        $this->postJson($this->route(), $this->payload())->assertSuccessful();
    }

    private function route(): string
    {
        return "/api/products/{$this->product->id}/views";
    }

    private function animated(): void
    {
        $this->source->withMeta([
            'facts' => ($this->source->facts() ?? []) + ['animated' => true, 'rigged' => true],
        ]);
    }

    private function payload(string $pass = 'wireframe', string $format = 'gltf'): array
    {
        return ['format' => $format, 'pass' => $pass, 'camera' => self::CAMERA];
    }
}
