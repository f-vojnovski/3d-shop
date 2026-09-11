<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductRouteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Without the pattern the id reaches Postgres as text, and the 500 that
     * comes back names the host, the database and the query.
     */
    public function test_a_non_numeric_id_is_not_found_rather_than_a_database_error(): void
    {
        $this->getJson('/api/products/abc')->assertNotFound();
    }

    public function test_a_numeric_id_still_resolves(): void
    {
        $seller = User::create([
            'name' => 'seller',
            'email' => 'seller@example.com',
            'password' => 'password123',
        ]);

        $product = Product::create([
            'name' => 'Concept car',
            'price_cents' => 2450,
            'user_id' => $seller->id,
        ]);

        $this->getJson("/api/products/{$product->id}")
            ->assertSuccessful()
            ->assertJsonPath('id', $product->id);
    }
}
