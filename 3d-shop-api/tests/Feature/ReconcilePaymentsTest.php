<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Payments\FakeGateway;
use App\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcilePaymentsTest extends TestCase
{
    use RefreshDatabase;

    private FakeGateway $gateway;

    private User $buyer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.payments.enabled' => true]);

        $this->gateway = new FakeGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        $seller = $this->user('seller');
        $this->buyer = $this->user('buyer');
        $this->product = Product::create([
            'name' => 'Concept car',
            'price_cents' => 14900,
            'user_id' => $seller->id,
        ]);
    }

    public function test_it_grants_an_order_whose_webhook_never_arrived(): void
    {
        $order = $this->stale();
        $this->gateway->statuses[$order->session_id] = 'paid';

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(Order::PAID, $order->fresh()->status);
        $this->assertSame(1, Sale::where('buyer_id', $this->buyer->id)->count());
    }

    public function test_it_leaves_an_order_the_gateway_says_is_unpaid(): void
    {
        $order = $this->stale();
        $this->gateway->statuses[$order->session_id] = 'unpaid';

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(Order::PENDING, $order->fresh()->status);
        $this->assertSame(0, Sale::count());
    }

    /** A session the gateway has no record of was never going to be paid. */
    public function test_it_fails_an_order_the_gateway_has_never_heard_of(): void
    {
        $order = $this->stale();

        $this->artisan('payments:reconcile')
            ->expectsOutputToContain('never heard of it')
            ->assertSuccessful();

        $this->assertSame(Order::FAILED, $order->fresh()->status);
    }

    /**
     * PayPal splits approval from payment, so a lost approval notification
     * leaves a buyer who agreed and a payment nobody took.
     */
    public function test_it_captures_an_approval_that_was_never_acted_on(): void
    {
        $order = $this->stale();
        $this->gateway->statuses[$order->session_id] = 'approved';

        $this->artisan('payments:reconcile')
            ->expectsOutputToContain('approved but never captured')
            ->assertSuccessful();

        $this->assertSame([$order->session_id], $this->gateway->captured);
        $this->assertSame(Order::PAID, $order->fresh()->status);
        $this->assertSame(1, Sale::count());
    }

    public function test_a_dry_run_captures_nothing(): void
    {
        $order = $this->stale();
        $this->gateway->statuses[$order->session_id] = 'approved';

        $this->artisan('payments:reconcile', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame([], $this->gateway->captured);
        $this->assertSame(Order::PENDING, $order->fresh()->status);
    }

    public function test_it_does_not_touch_a_recent_order(): void
    {
        $order = $this->stale(minutesAgo: 1);
        $this->gateway->statuses[$order->session_id] = 'paid';

        $this->artisan('payments:reconcile')
            ->expectsOutputToContain('No orders left waiting')
            ->assertSuccessful();

        $this->assertSame(Order::PENDING, $order->fresh()->status);
    }

    public function test_running_it_twice_grants_once(): void
    {
        $order = $this->stale();
        $this->gateway->statuses[$order->session_id] = 'paid';

        $this->artisan('payments:reconcile')->assertSuccessful();
        $paidAt = $order->fresh()->paid_at;
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(1, Sale::count());
        $this->assertEquals($paidAt, $order->fresh()->paid_at);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $order = $this->stale();
        $this->gateway->statuses[$order->session_id] = 'paid';

        $this->artisan('payments:reconcile', ['--dry-run' => true])
            ->expectsOutputToContain('would grant')
            ->assertSuccessful();

        $this->assertSame(Order::PENDING, $order->fresh()->status);
        $this->assertSame(0, Sale::count());
    }

    public function test_it_ignores_orders_that_never_reached_the_gateway(): void
    {
        $order = $this->stale();
        $order->update(['session_id' => null]);

        $this->artisan('payments:reconcile')
            ->expectsOutputToContain('No orders left waiting')
            ->assertSuccessful();
    }

    private function stale(int $minutesAgo = 60): Order
    {
        $order = Order::create([
            'buyer_id' => $this->buyer->id,
            'status' => Order::PENDING,
            'currency' => 'USD',
            'subtotal_cents' => 14900,
            'commission_cents' => 2235,
            'session_id' => 'cs_test_'.uniqid(),
        ]);

        $order->items()->create([
            'product_id' => $this->product->id,
            'seller_id' => $this->product->user_id,
            'price_cents' => 14900,
            'currency' => 'USD',
            'commission_cents' => 2235,
        ]);

        $order->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

        return $order->load('items');
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
