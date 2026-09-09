<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductFile;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    private function seller(string $name = 'seller'): User
    {
        return User::create([
            'name' => $name,
            'email' => $name.'@example.com',
            'password' => 'password123',
        ]);
    }

    private function product(User $owner, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Half-track',
            'price_cents' => 2450,
            'user_id' => $owner->id,
        ], $attributes));
    }

    public function test_the_database_rejects_a_duplicate_username(): void
    {
        $this->seller('filip');

        $this->expectException(QueryException::class);

        User::create([
            'name' => 'filip',
            'email' => 'someone-else@example.com',
            'password' => 'password123',
        ]);
    }

    public function test_the_database_rejects_an_unknown_file_kind(): void
    {
        $product = $this->product($this->seller());

        $this->expectException(QueryException::class);

        $product->files()->create([
            'kind' => 'preview-image',
            'disk' => 'public',
            'path' => 'typo.png',
        ]);
    }

    public function test_unlisted_products_are_hidden_from_the_catalogue(): void
    {
        $seller = $this->seller();
        $listed = $this->product($seller, ['name' => 'Listed']);
        $this->product($seller, ['name' => 'Hidden', 'unlisted' => true]);

        $names = collect($this->getJson('/api/products')->json('data'))->pluck('name');

        $this->assertSame(['Listed'], $names->all());
        $this->assertSame($listed->id, Product::where('unlisted', false)->sole()->id);
    }

    public function test_the_owner_still_sees_their_unlisted_products(): void
    {
        $seller = $this->seller();
        $this->product($seller, ['name' => 'Listed']);
        $this->product($seller, ['name' => 'Hidden', 'unlisted' => true]);

        Sanctum::actingAs($seller);

        $names = collect($this->getJson('/api/current-user-products')->json('data'))->pluck('name');

        $this->assertEqualsCanonicalizing(['Listed', 'Hidden'], $names->all());
    }

    public function test_repricing_a_product_does_not_change_past_sales(): void
    {
        $seller = $this->seller();
        $buyer = $this->seller('buyer');
        $product = $this->product($seller);

        $sale = Sale::create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'price_cents' => $product->price_cents,
            'currency' => $product->currency,
        ]);

        $product->update(['price_cents' => 999900]);

        $this->assertSame(2450, $sale->fresh()->price_cents);
    }

    public function test_prices_and_currency_default_and_round_trip_as_integers(): void
    {
        $product = $this->product($this->seller(), ['price_cents' => 1010]);

        $this->assertSame(1010, $product->fresh()->price_cents);
        $this->assertSame('USD', $product->fresh()->currency);
        $this->assertFalse($product->fresh()->unlisted);
    }

    public function test_a_sale_always_carries_a_creation_date(): void
    {
        $seller = $this->seller();
        $buyer = $this->seller('buyer');
        $product = $this->product($seller);

        $sale = Sale::create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'price_cents' => $product->price_cents,
        ]);

        $this->assertNotNull($sale->fresh()->created_at);
    }
}
