<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WithdrawListingTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $buyer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = $this->user('seller');
        $this->buyer = $this->user('buyer');
        $this->product = Product::create([
            'name' => 'Concept car',
            'price_cents' => 2450,
            'user_id' => $this->seller->id,
        ]);
    }

    public function test_the_seller_can_withdraw_their_own_listing(): void
    {
        Sanctum::actingAs($this->seller);

        $this->deleteJson("/api/products/{$this->product->id}")->assertNoContent();

        $this->assertTrue($this->product->fresh()->unlisted);
    }

    public function test_nobody_else_can(): void
    {
        Sanctum::actingAs($this->buyer);

        $this->deleteJson("/api/products/{$this->product->id}")->assertStatus(403);

        $this->assertFalse($this->product->fresh()->unlisted);
    }

    public function test_a_signed_out_visitor_cannot(): void
    {
        $this->deleteJson("/api/products/{$this->product->id}")->assertStatus(401);
    }

    public function test_a_withdrawn_listing_leaves_the_catalogue_and_search(): void
    {
        $this->withdraw();

        $this->getJson('/api/products')->assertSuccessful()->assertJsonCount(0, 'data');
        // Search is unpaginated, so its payload is a bare array.
        $this->getJson('/api/products/search/Concept')->assertSuccessful()->assertJsonCount(0);
        $this->getJson("/api/products-by-user/{$this->seller->id}")
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_seller_still_sees_it_among_their_own(): void
    {
        $this->withdraw();
        Sanctum::actingAs($this->seller);

        $this->getJson('/api/current-user-products')->assertSuccessful()->assertJsonCount(1, 'data');
    }

    /**
     * The rows and the files stay, which is the whole reason this withdraws
     * rather than deletes.
     */
    public function test_someone_who_bought_it_keeps_it(): void
    {
        Sale::create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->product->id,
            'price_cents' => 2450,
            'currency' => 'USD',
        ]);
        $this->withdraw();

        Sanctum::actingAs($this->buyer);

        $this->getJson('/api/owned-products')->assertSuccessful()->assertJsonCount(1, 'data');
        $this->getJson("/api/products-authenticated/{$this->product->id}")
            ->assertSuccessful()
            ->assertJsonPath('product_status', 'purchased');
    }

    public function test_a_withdrawn_listing_can_no_longer_be_bought(): void
    {
        $this->withdraw();
        Sanctum::actingAs($this->buyer);

        $this->postJson('/api/checkout/session', ['products' => [['id' => $this->product->id]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('products');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, Order::count());
    }

    private function withdraw(): void
    {
        $this->product->update(['unlisted' => true]);
    }

    private function user(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => "{$name}@example.com",
            'password' => 'password123',
        ]);
    }
}
