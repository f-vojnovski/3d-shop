<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListingQueryCountTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The listing used to ask the sales table whether each product was bought,
     * once per product and again for its download links.
     */
    public function test_the_listing_asks_about_sales_once_however_many_products(): void
    {
        $buyer = $this->user('buyer');
        $this->products(6, $buyer);

        Sanctum::actingAs($buyer);

        $this->assertSame(1, $this->salesQueriesFor('/api/products'));
    }

    public function test_adding_products_does_not_add_queries(): void
    {
        $buyer = $this->user('buyer');
        $this->products(2, $buyer);
        Sanctum::actingAs($buyer);
        $small = $this->countQueriesFor('/api/products');

        $this->products(8, $buyer);
        $large = $this->countQueriesFor('/api/products');

        $this->assertSame($small, $large);
    }

    public function test_it_still_knows_who_bought_what(): void
    {
        $buyer = $this->user('buyer');
        $products = $this->products(3, $buyer);

        Sale::create([
            'buyer_id' => $buyer->id,
            'product_id' => $products[1]->id,
            'price_cents' => 2450,
        ]);

        Sanctum::actingAs($buyer);

        $statuses = collect($this->getJson('/api/products')->assertSuccessful()->json('data'))
            ->pluck('product_status', 'id');

        $this->assertSame(Product::STATUS_NOT_PURCHASED, $statuses[$products[0]->id]);
        $this->assertSame(Product::STATUS_PURCHASED, $statuses[$products[1]->id]);
    }

    public function test_the_owner_is_still_the_owner(): void
    {
        $seller = $this->user('seller');
        $products = $this->products(2, $seller, owner: $seller);

        Sanctum::actingAs($seller);

        $statuses = collect($this->getJson('/api/products')->json('data'))
            ->pluck('product_status', 'id');

        $this->assertSame(Product::STATUS_OWNER, $statuses[$products[0]->id]);
    }

    private function salesQueriesFor(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson($url)->assertSuccessful();

        return collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains($query['query'], 'sales'))
            ->count();
    }

    private function countQueriesFor(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson($url)->assertSuccessful();

        return count(DB::getQueryLog());
    }

    private function user(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => "{$name}@example.com",
            'password' => 'password123',
        ]);
    }

    /** @return array<int, Product> */
    private function products(int $count, User $viewer, ?User $owner = null): array
    {
        $seller = $owner ?? $this->user('seller-'.uniqid());
        $made = [];

        for ($i = 0; $i < $count; $i++) {
            $made[] = Product::create([
                'name' => 'Model '.uniqid(),
                'price_cents' => 2450,
                'user_id' => $seller->id,
            ]);
        }

        return $made;
    }
}
