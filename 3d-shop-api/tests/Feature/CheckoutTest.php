<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Payments\FakeGateway;
use App\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private User $seller;

    private Product $product;

    private FakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.payments.enabled' => true]);

        $this->gateway = new FakeGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        $this->seller = $this->user('seller');
        $this->buyer = $this->user('buyer');
        $this->product = $this->productFor($this->seller, 14900);

        Sanctum::actingAs($this->buyer);
    }

    public function test_a_session_prices_the_cart_from_the_database(): void
    {
        $body = $this->open()->assertSuccessful()->json();

        $this->assertSame(Order::PENDING, $body['status']);
        $this->assertSame(14900, $body['subtotal_cents']);
        $this->assertSame('https://checkout.test/cs_test_'.$body['id'], $body['checkout_url']);
        $this->assertSame([['order_id' => $body['id'], 'amount' => 14900]], $this->gateway->sessions);
    }

    /** The request carries ids. Anything else it sends is ignored. */
    public function test_a_price_in_the_request_is_ignored(): void
    {
        $body = $this->postJson('/api/checkout/session', [
            'products' => [['id' => $this->product->id, 'price_cents' => 1]],
        ])->assertSuccessful()->json();

        $this->assertSame(14900, $body['subtotal_cents']);
    }

    public function test_the_order_records_the_commission(): void
    {
        $id = $this->open()->json('id');

        $order = Order::with('items')->findOrFail($id);

        // 15% of 14900, floored.
        $this->assertSame(2235, $order->commission_cents);
        $this->assertSame(2235, $order->items->first()->commission_cents);
        $this->assertSame(12665, $order->items->first()->sellerEarnsCents());
    }

    public function test_nothing_is_granted_until_the_payment_is_confirmed(): void
    {
        $this->open()->assertSuccessful();

        $this->assertSame(0, Sale::count());
        $this->getJson("/api/products-authenticated/{$this->product->id}")
            ->assertJsonPath('product_status', 'not-purchased');
    }

    /**
     * A Sale row is the download grant, so any route that writes one without
     * an order gives the file away. `POST /api/sales/buy` did exactly that and
     * survived the move to a payment provider because nothing called it.
     */
    public function test_no_route_grants_a_product_without_an_order(): void
    {
        $this->postJson('/api/sales/buy', ['products' => [['id' => $this->product->id]]])
            ->assertNotFound();

        $this->assertSame(0, Sale::count());
    }

    public function test_a_withdrawn_product_cannot_be_bought(): void
    {
        $this->product->update(['unlisted' => true]);

        $this->open()->assertStatus(422)->assertJsonValidationErrors('products');
        $this->assertSame(0, Order::count());
    }

    public function test_your_own_product_cannot_be_bought(): void
    {
        Sanctum::actingAs($this->seller);

        $this->open()->assertStatus(422)->assertJsonValidationErrors('products');
    }

    public function test_a_product_already_owned_cannot_be_bought_again(): void
    {
        Sale::create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->product->id,
            'price_cents' => 14900,
            'currency' => 'USD',
        ]);

        $this->open()->assertStatus(422)->assertJsonValidationErrors('products');
    }

    public function test_a_cart_mixing_currencies_is_refused(): void
    {
        $other = $this->productFor($this->seller, 5000);
        $other->update(['currency' => 'EUR']);

        $this->postJson('/api/checkout/session', [
            'products' => [['id' => $this->product->id], ['id' => $other->id]],
        ])->assertStatus(422)->assertJsonValidationErrors('products');
    }

    public function test_an_empty_cart_is_refused(): void
    {
        $this->postJson('/api/checkout/session', ['products' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('products');
    }

    public function test_a_product_that_does_not_exist_is_refused(): void
    {
        $this->postJson('/api/checkout/session', ['products' => [['id' => 99999]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('products.0.id');
    }

    /**
     * Stripe will not open a session for nothing, and prices may be zero, so a
     * free product has to be granted without one.
     */
    public function test_a_free_product_is_granted_without_a_session(): void
    {
        $free = $this->productFor($this->seller, 0);

        $body = $this->postJson('/api/checkout/session', ['products' => [['id' => $free->id]]])
            ->assertSuccessful()
            ->json();

        $this->assertSame(Order::PAID, $body['status']);
        $this->assertArrayNotHasKey('checkout_url', $body);
        $this->assertSame([], $this->gateway->sessions);
        $this->assertSame(1, Sale::where('product_id', $free->id)->count());
    }

    /**
     * Free is handled above; the sliver between free and the provider's floor
     * used to reach the provider and come back as an API error.
     */
    public function test_a_price_below_the_provider_minimum_is_refused_cleanly(): void
    {
        $this->app->instance(PaymentGateway::class, new class extends FakeGateway
        {
            public function minimumChargeCents(): int
            {
                return 50;
            }
        });

        $cheap = $this->productFor($this->seller, 30);

        $this->postJson('/api/checkout/session', ['products' => [['id' => $cheap->id]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('products');

        $this->assertSame(0, Sale::where('product_id', $cheap->id)->count());
        $this->assertSame(Order::FAILED, Order::latest('id')->first()->status);
    }

    public function test_a_price_on_the_minimum_is_accepted(): void
    {
        $this->app->instance(PaymentGateway::class, new class extends FakeGateway
        {
            public function minimumChargeCents(): int
            {
                return 50;
            }
        });

        $product = $this->productFor($this->seller, 50);

        $this->postJson('/api/checkout/session', ['products' => [['id' => $product->id]]])
            ->assertSuccessful();
    }

    public function test_with_payments_switched_off_checkout_grants_immediately(): void
    {
        config(['services.payments.enabled' => false]);

        $body = $this->open()->assertSuccessful()->json();

        $this->assertSame(Order::PAID, $body['status']);
        $this->assertSame([], $this->gateway->sessions);
        $this->assertSame(1, Sale::count());
    }

    public function test_a_buyer_can_poll_their_own_order(): void
    {
        $id = $this->open()->json('id');

        $this->getJson("/api/orders/{$id}")
            ->assertSuccessful()
            ->assertJsonPath('status', Order::PENDING)
            ->assertJsonPath('subtotal_cents', 14900);
    }

    public function test_nobody_else_can_poll_it(): void
    {
        $id = $this->open()->json('id');
        Sanctum::actingAs($this->seller);

        $this->getJson("/api/orders/{$id}")->assertStatus(403);
    }

    public function test_a_signed_out_visitor_cannot_open_a_session(): void
    {
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer nonsense')
            ->postJson('/api/checkout/session', ['products' => [['id' => $this->product->id]]])
            ->assertStatus(401);
    }

    private function open()
    {
        return $this->postJson('/api/checkout/session', [
            'products' => [['id' => $this->product->id]],
        ]);
    }

    private function user(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => "{$name}@example.com",
            'password' => 'password123',
        ]);
    }

    private function productFor(User $seller, int $cents): Product
    {
        return Product::create([
            'name' => 'Concept car '.uniqid(),
            'price_cents' => $cents,
            'user_id' => $seller->id,
        ]);
    }
}
