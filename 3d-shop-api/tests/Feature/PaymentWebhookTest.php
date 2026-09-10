<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Payments\FakeGateway;
use App\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The payloads are signed with the real Stripe scheme and verified by the real
 * Stripe code. The signature is the only thing standing between a stranger and
 * a free download, so no test here goes around it.
 */
class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test';

    private User $buyer;

    private User $seller;

    private Product $product;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.payments.enabled' => true, 'services.stripe.webhook_secret' => self::SECRET]);
        $this->app->instance(PaymentGateway::class, new FakeGateway(self::SECRET));

        $this->seller = $this->user('seller');
        $this->buyer = $this->user('buyer');
        $this->product = Product::create([
            'name' => 'Concept car',
            'price_cents' => 14900,
            'user_id' => $this->seller->id,
        ]);

        Sanctum::actingAs($this->buyer);
        $id = $this->postJson('/api/checkout/session', [
            'products' => [['id' => $this->product->id]],
        ])->assertSuccessful()->json('id');

        $this->order = Order::with('items')->findOrFail($id);
    }

    public function test_a_completed_session_grants_the_download(): void
    {
        $this->send($this->completed())->assertSuccessful();

        $this->assertSame(Order::PAID, $this->order->fresh()->status);
        $this->assertSame(1, Sale::where('buyer_id', $this->buyer->id)->count());
        $this->getJson("/api/products-authenticated/{$this->product->id}")
            ->assertJsonPath('product_status', 'purchased');
    }

    public function test_the_sale_records_the_order_item_and_the_price_paid(): void
    {
        $this->send($this->completed())->assertSuccessful();

        $sale = Sale::firstWhere('buyer_id', $this->buyer->id);

        $this->assertSame($this->order->items->first()->id, $sale->order_item_id);
        $this->assertSame(14900, $sale->price_cents);
    }

    public function test_the_event_is_recorded_as_received_and_handled(): void
    {
        $event = $this->completed();

        $this->send($event)->assertSuccessful();

        $stored = WebhookEvent::findOrFail($event['id']);
        $this->assertSame('checkout.session.completed', $stored->type);
        $this->assertNotNull($stored->handled_at);
    }

    /** Stripe retries. The second delivery must change nothing. */
    public function test_the_same_event_twice_grants_once(): void
    {
        $event = $this->completed();

        $this->send($event)->assertSuccessful();
        $this->send($event)->assertSuccessful()->assertJsonPath('message', 'Already received.');

        $this->assertSame(1, Sale::count());
        $this->assertSame(1, WebhookEvent::count());
    }

    /** Two distinct events for one order, which retries can also produce. */
    public function test_two_events_for_one_order_grant_once(): void
    {
        $this->send($this->completed())->assertSuccessful();
        $this->send($this->completed(['id' => 'evt_second']))->assertSuccessful();

        $this->assertSame(1, Sale::count());
        $this->assertSame(Order::PAID, $this->order->fresh()->status);
    }

    public function test_fulfilling_after_the_reconciler_already_did_changes_nothing(): void
    {
        app(\App\Payments\Checkout::class)->fulfil($this->order);
        $paidAt = $this->order->fresh()->paid_at;

        $this->send($this->completed())->assertSuccessful();

        $this->assertSame(1, Sale::count());
        $this->assertEquals($paidAt, $this->order->fresh()->paid_at);
    }

    public function test_an_unsigned_payload_is_refused(): void
    {
        $this->postJson('/api/payments/webhook', $this->completed())->assertStatus(400);

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_a_payload_signed_with_the_wrong_secret_is_refused(): void
    {
        $this->send($this->completed(), secret: 'whsec_not_ours')->assertStatus(400);

        $this->assertSame(0, Sale::count());
    }

    public function test_a_tampered_payload_is_refused(): void
    {
        $event = $this->completed();
        $body = json_encode($event);
        $signature = $this->signature($body, self::SECRET);

        // Same signature, different body: the amount doubled in transit.
        $tampered = str_replace('14900', '29800', $body);

        $this->call('POST', '/api/payments/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $tampered)->assertStatus(400);
    }

    public function test_a_signature_from_long_ago_is_refused(): void
    {
        $body = json_encode($this->completed());

        $this->call('POST', '/api/payments/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $this->signature($body, self::SECRET, now()->subHour()->timestamp),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(400);
    }

    public function test_a_body_that_is_not_json_is_refused(): void
    {
        $body = 'not json at all';

        $this->call('POST', '/api/payments/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $this->signature($body, self::SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(400);
    }

    /** Another environment can share a Stripe account; that is not our error. */
    public function test_an_event_for_an_unknown_order_is_accepted_and_ignored(): void
    {
        $this->send($this->completed(['data' => ['object' => [
            'id' => 'cs_test_unknown',
            'metadata' => ['order_id' => '424242'],
            'payment_status' => 'paid',
            'amount_total' => 14900,
        ]]]))->assertSuccessful();

        $this->assertSame(0, Sale::count());
    }

    public function test_an_amount_that_disagrees_with_the_order_is_refused(): void
    {
        $this->send($this->completed(['data' => ['object' => [
            'id' => (string) $this->order->session_id,
            'metadata' => ['order_id' => (string) $this->order->id],
            'payment_status' => 'paid',
            'amount_total' => 100,
        ]]]))->assertSuccessful();

        $this->assertSame(0, Sale::count());
        $this->assertSame(Order::FAILED, $this->order->fresh()->status);
    }

    /** The snapshot is the agreement, so a later reprice cannot move it. */
    public function test_a_price_change_after_the_session_does_not_change_the_charge(): void
    {
        $this->product->update(['price_cents' => 100]);

        $this->send($this->completed())->assertSuccessful();

        $this->assertSame(14900, Sale::firstWhere('buyer_id', $this->buyer->id)->price_cents);
        $this->assertSame(Order::PAID, $this->order->fresh()->status);
    }

    public function test_a_session_completed_without_payment_grants_nothing(): void
    {
        $this->send($this->completed(['data' => ['object' => [
            'id' => (string) $this->order->session_id,
            'metadata' => ['order_id' => (string) $this->order->id],
            'payment_status' => 'unpaid',
            'amount_total' => 14900,
        ]]]))->assertSuccessful();

        $this->assertSame(0, Sale::count());
        $this->assertSame(Order::PENDING, $this->order->fresh()->status);
    }

    public function test_a_failed_payment_settles_the_order_without_granting(): void
    {
        $this->send($this->event('payment_intent.payment_failed', [
            'id' => 'pi_test',
            'metadata' => ['order_id' => (string) $this->order->id],
            'last_payment_error' => ['message' => 'Your card was declined.'],
        ]))->assertSuccessful();

        $order = $this->order->fresh();

        $this->assertSame(Order::FAILED, $order->status);
        $this->assertSame('Your card was declined.', $order->failure_reason);
        $this->assertSame(0, Sale::count());
    }

    public function test_an_expired_session_settles_the_order(): void
    {
        $this->send($this->event('checkout.session.expired', [
            'id' => (string) $this->order->session_id,
            'metadata' => ['order_id' => (string) $this->order->id],
        ]))->assertSuccessful();

        $this->assertSame(Order::EXPIRED, $this->order->fresh()->status);
    }

    public function test_a_refund_takes_the_download_back(): void
    {
        $this->send($this->completed())->assertSuccessful();
        $this->order->update(['payment_intent_id' => 'pi_refunded']);

        $this->send($this->event('charge.refunded', [
            'id' => 'ch_test',
            'payment_intent' => 'pi_refunded',
        ]))->assertSuccessful();

        $this->assertSame(Order::REFUNDED, $this->order->fresh()->status);
        $this->assertSame(0, Sale::count());
        $this->getJson("/api/products-authenticated/{$this->product->id}")
            ->assertJsonPath('product_status', 'not-purchased');
    }

    public function test_a_refund_for_an_order_that_never_paid_is_harmless(): void
    {
        $this->order->update(['payment_intent_id' => 'pi_never_paid']);

        $this->send($this->event('charge.refunded', [
            'id' => 'ch_test',
            'payment_intent' => 'pi_never_paid',
        ]))->assertSuccessful();

        $this->assertSame(Order::REFUNDED, $this->order->fresh()->status);
        $this->assertSame(0, Sale::count());
    }

    /** A goodwill adjustment is not grounds for taking the files away. */
    public function test_a_partial_refund_is_recorded_and_leaves_access_alone(): void
    {
        $this->send($this->completed())->assertSuccessful();
        $this->order->update(['payment_intent_id' => 'pi_partial']);

        $this->send($this->event('charge.refunded', [
            'id' => 'ch_test',
            'payment_intent' => 'pi_partial',
            'amount' => 14900,
            'amount_refunded' => 5000,
        ]))->assertSuccessful();

        $order = $this->order->fresh();

        $this->assertSame(Order::PAID, $order->status);
        $this->assertStringContainsString('Partially refunded', (string) $order->failure_reason);
        $this->assertSame(1, Sale::count());
    }

    public function test_a_full_refund_reported_with_amounts_still_revokes(): void
    {
        $this->send($this->completed())->assertSuccessful();
        $this->order->update(['payment_intent_id' => 'pi_full']);

        $this->send($this->event('charge.refunded', [
            'id' => 'ch_test',
            'payment_intent' => 'pi_full',
            'amount' => 14900,
            'amount_refunded' => 14900,
        ]))->assertSuccessful();

        $this->assertSame(Order::REFUNDED, $this->order->fresh()->status);
        $this->assertSame(0, Sale::count());
    }

    /** Whether to revoke on a dispute is a human decision, so we only flag. */
    public function test_a_dispute_is_flagged_without_touching_access(): void
    {
        $this->send($this->completed())->assertSuccessful();
        $this->order->update(['payment_intent_id' => 'pi_disputed']);

        $this->send($this->event('charge.dispute.created', [
            'id' => 'ch_test',
            'payment_intent' => 'pi_disputed',
            'reason' => 'fraudulent',
        ]))->assertSuccessful();

        $order = $this->order->fresh();

        $this->assertSame(Order::PAID, $order->status);
        $this->assertStringContainsString('fraudulent', (string) $order->failure_reason);
        $this->assertSame(1, Sale::count());
    }

    public function test_the_order_item_records_which_seller_is_owed(): void
    {
        $item = $this->order->items->first();

        $this->assertSame($this->seller->id, $item->seller_id);
        $this->assertSame(2235, $item->commission_cents);
        $this->assertSame(12665, $item->sellerEarnsCents());
    }

    public function test_the_currency_in_the_event_must_match_the_order(): void
    {
        $this->send($this->completed(['data' => ['object' => ['currency' => 'eur']]]))
            ->assertSuccessful();

        $this->assertSame(0, Sale::count());
        $this->assertSame(Order::FAILED, $this->order->fresh()->status);
    }

    public function test_only_the_products_in_the_order_are_granted(): void
    {
        $other = Product::create([
            'name' => 'Another car',
            'price_cents' => 5000,
            'user_id' => $this->seller->id,
        ]);

        $this->send($this->completed())->assertSuccessful();

        $this->assertSame(1, Sale::count());
        $this->assertSame(0, Sale::where('product_id', $other->id)->count());
    }

    /**
     * The reconciler only looks at pending orders, so failing one here would
     * strand a payment the buyer already approved.
     */
    public function test_a_capture_that_fails_leaves_the_order_pending(): void
    {
        $gateway = new class(self::SECRET) extends FakeGateway
        {
            public function capture(string $sessionId): bool
            {
                return false;
            }
        };
        $this->app->instance(PaymentGateway::class, $gateway);

        $event = new \App\Payments\PaymentEvent(
            id: 'evt_capture_fails',
            kind: \App\Payments\PaymentEvent::APPROVED,
            providerType: 'CHECKOUT.ORDER.APPROVED',
            orderReference: (string) $this->order->id,
            sessionId: (string) $this->order->session_id,
        );

        try {
            (new \App\Jobs\HandlePaymentEvent($event))->handle(
                app(\App\Payments\Checkout::class),
                $gateway
            );
            $this->fail('the job should have thrown so the queue retries');
        } catch (\RuntimeException) {
            // Expected: the queue retries rather than settling the order.
        }

        $this->assertSame(Order::PENDING, $this->order->fresh()->status);
        $this->assertSame(0, Sale::count());
    }

    public function test_an_event_type_we_do_not_handle_is_accepted_quietly(): void
    {
        $this->send($this->event('customer.created', ['id' => 'cus_test']))->assertSuccessful();

        $this->assertSame(Order::PENDING, $this->order->fresh()->status);
    }

    // ------------------------------------------------------------------ helpers

    private function completed(array $overrides = []): array
    {
        return array_replace_recursive($this->event('checkout.session.completed', [
            'id' => (string) $this->order->session_id,
            'metadata' => ['order_id' => (string) $this->order->id],
            'payment_status' => 'paid',
            'amount_total' => 14900,
            'payment_intent' => 'pi_test',
        ]), $overrides);
    }

    private function event(string $type, array $object): array
    {
        return [
            'id' => 'evt_'.substr(md5($type.json_encode($object)), 0, 16),
            'type' => $type,
            'data' => ['object' => $object],
        ];
    }

    private function send(array $event, string $secret = self::SECRET)
    {
        $body = json_encode($event);

        return $this->call('POST', '/api/payments/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $this->signature($body, $secret),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    /** Stripe's own scheme: t=<unix>,v1=<hmac sha256 of "t.body">. */
    private function signature(string $body, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= now()->timestamp;

        return sprintf(
            't=%d,v1=%s',
            $timestamp,
            hash_hmac('sha256', "{$timestamp}.{$body}", $secret)
        );
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
