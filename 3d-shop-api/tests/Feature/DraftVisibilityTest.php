<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductFile;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The seller's screen says nobody can see an unpublished listing but them. The
 * catalogue honoured that; every route taking an id did not.
 */
class DraftVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $stranger;

    private Product $draft;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('models');
        Storage::fake('public');

        $this->seller = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->draft = $this->unpublishedProduct($this->seller);
    }

    public function test_a_stranger_signed_out_cannot_read_a_draft(): void
    {
        $this->getJson("/api/products/{$this->draft->id}")->assertNotFound();
    }

    public function test_a_stranger_signed_in_cannot_read_a_draft(): void
    {
        Sanctum::actingAs($this->stranger);

        $this->getJson("/api/products/{$this->draft->id}")->assertNotFound();
    }

    public function test_a_stranger_cannot_fetch_a_drafts_geometry(): void
    {
        $this->get("/api/products/{$this->draft->id}/proxy/obj")->assertNotFound();
    }

    /** Each of these starts a container, so reaching one is a bill as well as a leak. */
    public function test_a_stranger_cannot_queue_a_render_against_a_draft(): void
    {
        Sanctum::actingAs($this->stranger);

        $this->postJson("/api/products/{$this->draft->id}/views", [
            'pass' => 'shaded',
            'camera' => ['position' => [3, 2, 4], 'target' => [0, 0, 0], 'fov' => 75],
        ])->assertNotFound();
    }

    public function test_the_seller_still_sees_their_own_draft(): void
    {
        Sanctum::actingAs($this->seller);

        $this->getJson("/api/products/{$this->draft->id}")->assertSuccessful();
    }

    /** Withdrawing a listing must not take it away from whoever paid for it. */
    public function test_a_buyer_still_reaches_a_listing_that_was_withdrawn(): void
    {
        $buyer = User::factory()->create();

        Sale::create([
            'buyer_id' => $buyer->id,
            'product_id' => $this->draft->id,
            'price_cents' => $this->draft->price_cents,
        ]);

        Sanctum::actingAs($buyer);

        $this->getJson("/api/products/{$this->draft->id}")->assertSuccessful();
    }

    private function unpublishedProduct(User $seller): Product
    {
        $product = Product::create([
            'name' => 'Not finished yet',
            'description' => 'Nobody should see this.',
            'price_cents' => 2500,
            'currency' => 'USD',
            'user_id' => $seller->id,
            'unlisted' => true,
        ]);

        Storage::disk('models')->put('obj_files/draft.obj', "v 0 0 0\n");

        ProductFile::create([
            'product_id' => $product->id,
            'kind' => ProductFile::KIND_DELIVERABLE,
            'format' => 'obj',
            'disk' => 'models',
            'path' => 'obj_files/draft.obj',
            'sort' => 0,
            'bytes' => 9,
            'checksum' => hash('sha256', "v 0 0 0\n"),
            'meta' => ['faces' => 1, 'sniffed_format' => 'obj'],
        ]);

        return $product->refresh()->load('files');
    }
}
