<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Payments\Checkout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Providers re-deliver. A paid event arriving after a refund must not hand the
 * download back, and a refund arriving twice must not run twice.
 */
class RefundedOrderTest extends TestCase
{
    use RefreshDatabase;

    private Checkout $checkout;

    private User $buyer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkout = app(Checkout::class);
        $this->buyer = User::factory()->create();
        $this->product = Product::create([
            'name' => 'A model',
            'price_cents' => 4500,
            'currency' => 'USD',
            'user_id' => User::factory()->create()->id,
            'published_at' => now(),
        ]);
    }

    public function test_a_paid_event_after_a_refund_does_not_grant_the_download_again(): void
    {
        $order = $this->anOrder();

        $this->checkout->fulfil($order);
        $this->assertTrue($this->product->fresh()->isDownloadableBy($this->buyer->id));

        $this->checkout->refund($order->refresh());
        $this->assertFalse($this->product->fresh()->isDownloadableBy($this->buyer->id));

        // The provider sends the original paid notification again.
        $this->checkout->fulfil($order->refresh());

        $this->assertFalse(
            $this->product->fresh()->isDownloadableBy($this->buyer->id),
            'A refunded order was fulfilled a second time.'
        );
        $this->assertSame(Order::REFUNDED, $order->refresh()->status);
        $this->assertSame(0, Sale::where('buyer_id', $this->buyer->id)->count());
    }

    public function test_a_refund_delivered_twice_settles_once(): void
    {
        $order = $this->anOrder();
        $this->checkout->fulfil($order);

        $this->checkout->refund($order->refresh());
        $refundedAt = $order->refresh()->refunded_at;

        $this->checkout->refund($order->refresh());

        $this->assertEquals($refundedAt, $order->refresh()->refunded_at, 'The second refund moved the timestamp.');
        $this->assertSame(0, Sale::where('buyer_id', $this->buyer->id)->count());
    }

    public function test_a_paid_event_delivered_twice_grants_one_sale(): void
    {
        $order = $this->anOrder();

        $this->checkout->fulfil($order);
        $this->checkout->fulfil($order->refresh());

        $this->assertSame(1, Sale::where('buyer_id', $this->buyer->id)->count());
    }

    /** Built the way checkout builds one, so the fixture cannot drift from it. */
    private function anOrder(): Order
    {
        return $this->checkout->open($this->buyer->id, [$this->product->id]);
    }
}
