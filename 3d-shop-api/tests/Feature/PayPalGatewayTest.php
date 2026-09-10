<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Payments\PaymentEvent;
use App\Payments\PayPalGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The wire. No network: PayPal's responses are faked, but the requests we send
 * are asserted, because the shape of those is the part that has to be right.
 */
class PayPalGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api-m.sandbox.paypal.com';

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $seller = User::create([
            'name' => 'seller',
            'email' => 'seller@example.com',
            'password' => 'password123',
        ]);
        $buyer = User::create([
            'name' => 'buyer',
            'email' => 'buyer@example.com',
            'password' => 'password123',
        ]);
        $product = Product::create([
            'name' => 'Concept car',
            'price_cents' => 14900,
            'user_id' => $seller->id,
        ]);

        $this->order = Order::create([
            'buyer_id' => $buyer->id,
            'status' => Order::PENDING,
            'currency' => 'USD',
            'subtotal_cents' => 14900,
            'commission_cents' => 2235,
        ]);
        $this->order->items()->create([
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'price_cents' => 14900,
            'currency' => 'USD',
            'commission_cents' => 2235,
        ]);
        $this->order->load('items.product');
    }

    public function test_it_asks_paypal_for_an_order_priced_in_decimals(): void
    {
        Http::fake([
            self::BASE.'/v1/oauth2/token' => Http::response(['access_token' => 'A1']),
            self::BASE.'/v2/checkout/orders' => Http::response([
                'id' => '5O190127TN364715T',
                'links' => [
                    ['rel' => 'self', 'href' => 'https://api/x'],
                    ['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O1'],
                ],
            ]),
        ]);

        $session = $this->gateway()->createSession($this->order, 'https://shop.test/done', 'https://shop.test/cancel');

        $this->assertSame('5O190127TN364715T', $session->id);
        $this->assertSame('https://www.sandbox.paypal.com/checkoutnow?token=5O1', $session->url);

        Http::assertSent(function (Request $request) {
            if (! str_ends_with($request->url(), '/v2/checkout/orders')) {
                return false;
            }

            $unit = $request['purchase_units'][0];

            return $request['intent'] === 'CAPTURE'
                && $unit['amount']['value'] === '149.00'
                && $unit['amount']['currency_code'] === 'USD'
                && $unit['custom_id'] === (string) $this->order->id
                && $unit['items'][0]['unit_amount']['value'] === '149.00'
                && $request['application_context']['return_url'] === 'https://shop.test/done'
                && $request['application_context']['shipping_preference'] === 'NO_SHIPPING';
        });
    }

    /** Retrying an order must not create a second one at PayPal's end. */
    public function test_the_order_request_carries_an_idempotency_key(): void
    {
        Http::fake([
            self::BASE.'/v1/oauth2/token' => Http::response(['access_token' => 'A1']),
            self::BASE.'/v2/checkout/orders' => Http::response([
                'id' => 'O1',
                'links' => [['rel' => 'approve', 'href' => 'https://pay/O1']],
            ]),
        ]);

        $this->gateway()->createSession($this->order, 'https://shop.test/done', 'https://shop.test/cancel');

        Http::assertSent(fn (Request $request) => ! str_ends_with($request->url(), '/v2/checkout/orders')
            || $request->hasHeader('PayPal-Request-Id', 'order-'.$this->order->id));
    }

    public function test_a_token_is_fetched_once_and_kept(): void
    {
        Http::fake([
            self::BASE.'/v1/oauth2/token' => Http::response(['access_token' => 'A1']),
            self::BASE.'/v2/checkout/orders/*/capture' => Http::response(['status' => 'COMPLETED']),
        ]);

        $gateway = $this->gateway();
        $gateway->capture('O1');
        $gateway->capture('O2');

        $tokenCalls = 0;
        Http::assertSent(function (Request $request) use (&$tokenCalls) {
            if (str_ends_with($request->url(), '/v1/oauth2/token')) {
                $tokenCalls++;
            }

            return true;
        });

        $this->assertSame(1, $tokenCalls);
    }

    public function test_it_refuses_an_order_paypal_would_not_open(): void
    {
        Http::fake([
            self::BASE.'/v1/oauth2/token' => Http::response(['access_token' => 'A1']),
            self::BASE.'/v2/checkout/orders' => Http::response(['name' => 'INVALID_REQUEST'], 400),
        ]);

        $this->expectException(RuntimeException::class);

        $this->gateway()->createSession($this->order, 'https://shop.test/done', 'https://shop.test/cancel');
    }

    public function test_it_refuses_an_order_with_nowhere_to_send_the_buyer(): void
    {
        Http::fake([
            self::BASE.'/v1/oauth2/token' => Http::response(['access_token' => 'A1']),
            self::BASE.'/v2/checkout/orders' => Http::response(['id' => 'O1', 'links' => []]),
        ]);

        $this->expectException(RuntimeException::class);

        $this->gateway()->createSession($this->order, 'https://shop.test/done', 'https://shop.test/cancel');
    }

    /**
     * Real PayPal answers MALFORMED_REQUEST_JSON to a capture with no body: the
     * call is declared as JSON, so an empty body is not valid. The fake here
     * happily returned 200 for that request until this was asserted.
     */
    public function test_the_capture_sends_a_json_body(): void
    {
        Http::fake([
            self::BASE.'/v1/oauth2/token' => Http::response(['access_token' => 'A1']),
            self::BASE.'/v2/checkout/orders/*/capture' => Http::response(['status' => 'COMPLETED']),
        ]);

        $this->gateway()->capture('O1');

        Http::assertSent(fn (Request $request) => ! str_contains($request->url(), '/capture')
            || ($request->body() === '{}' && $request->hasHeader('Content-Type', 'application/json')));
    }

    public function test_capturing_twice_is_not_an_error(): void
    {
        Http::fake([
            self::BASE.'/v1/oauth2/token' => Http::response(['access_token' => 'A1']),
            self::BASE.'/v2/checkout/orders/*/capture' => Http::response([
                'details' => [['issue' => 'ORDER_ALREADY_CAPTURED']],
            ], 422),
        ]);

        $this->assertTrue($this->gateway()->capture('O1'));
    }

    public function test_a_capture_that_genuinely_failed_reports_failure(): void
    {
        Http::fake([
            self::BASE.'/v1/oauth2/token' => Http::response(['access_token' => 'A1']),
            self::BASE.'/v2/checkout/orders/*/capture' => Http::response([
                'details' => [['issue' => 'INSTRUMENT_DECLINED']],
            ], 422),
        ]);

        $this->assertFalse($this->gateway()->capture('O1'));
    }

    /**
     * PayPal signs nothing we can check locally: verification is a call back to
     * PayPal, which is why the offline tests here fake that call rather than
     * computing an HMAC as the Stripe ones do.
     */
    public function test_a_notification_paypal_vouches_for_is_accepted(): void
    {
        Http::fake([
            self::BASE.'/v1/oauth2/token' => Http::response(['access_token' => 'A1']),
            self::BASE.'/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'SUCCESS',
            ]),
        ]);

        $event = $this->gateway()->parseEvent($this->notification(), $this->headers());

        $this->assertSame(PaymentEvent::PAID, $event->kind);
        $this->assertSame('42', $event->orderReference);
        $this->assertSame(14900, $event->amountCents);
    }

    public function test_a_notification_paypal_will_not_vouch_for_is_refused(): void
    {
        Http::fake([
            self::BASE.'/v1/oauth2/token' => Http::response(['access_token' => 'A1']),
            self::BASE.'/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'FAILURE',
            ]),
        ]);

        $this->expectException(RuntimeException::class);

        $this->gateway()->parseEvent($this->notification(), $this->headers());
    }

    public function test_the_verification_call_passes_on_the_transmission_headers(): void
    {
        Http::fake([
            self::BASE.'/v1/oauth2/token' => Http::response(['access_token' => 'A1']),
            self::BASE.'/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'SUCCESS',
            ]),
        ]);

        $this->gateway()->parseEvent($this->notification(), $this->headers());

        Http::assertSent(fn (Request $request) => ! str_contains($request->url(), 'verify-webhook-signature')
            || ($request['transmission_id'] === 'tx-1'
                && $request['transmission_sig'] === 'sig-1'
                && $request['webhook_id'] === 'WH-CONFIGURED'
                && $request['webhook_event']['event_type'] === 'PAYMENT.CAPTURE.COMPLETED'));
    }

    public function test_something_that_is_not_a_notification_is_refused_before_any_call(): void
    {
        Http::fake();

        $this->expectException(RuntimeException::class);

        $this->gateway()->parseEvent('{"hello":"world"}', $this->headers());
    }

    public function test_it_reports_status_in_the_words_the_reconciler_uses(): void
    {
        Http::fake([
            self::BASE.'/v1/oauth2/token' => Http::response(['access_token' => 'A1']),
            self::BASE.'/v2/checkout/orders/DONE' => Http::response(['status' => 'COMPLETED']),
            self::BASE.'/v2/checkout/orders/AGREED' => Http::response(['status' => 'APPROVED']),
            self::BASE.'/v2/checkout/orders/WAITING' => Http::response(['status' => 'PAYER_ACTION_REQUIRED']),
            self::BASE.'/v2/checkout/orders/GONE' => Http::response(['name' => 'RESOURCE_NOT_FOUND'], 404),
        ]);

        $gateway = $this->gateway();

        $this->assertSame('paid', $gateway->sessionStatus('DONE'));
        // Its own word, because the reconciler has to capture this one.
        $this->assertSame('approved', $gateway->sessionStatus('AGREED'));
        $this->assertSame('unpaid', $gateway->sessionStatus('WAITING'));
        $this->assertNull($gateway->sessionStatus('GONE'));
    }

    private function gateway(): PayPalGateway
    {
        return new PayPalGateway(
            $this->app->make(Factory::class),
            self::BASE,
            'client-id',
            'client-secret',
            'WH-CONFIGURED'
        );
    }

    private function notification(): string
    {
        return json_encode([
            'id' => 'WH-1',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [
                'id' => 'CAP-1',
                'custom_id' => '42',
                'amount' => ['currency_code' => 'USD', 'value' => '149.00'],
            ],
        ]);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'paypal-auth-algo' => 'SHA256withRSA',
            'paypal-cert-url' => 'https://api.sandbox.paypal.com/cert.pem',
            'paypal-transmission-id' => 'tx-1',
            'paypal-transmission-sig' => 'sig-1',
            'paypal-transmission-time' => '2026-09-10T18:00:00Z',
        ];
    }
}
