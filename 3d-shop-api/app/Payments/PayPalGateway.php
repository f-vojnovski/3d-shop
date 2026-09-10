<?php

namespace App\Payments;

use App\Models\Order;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * PayPal Orders v2.
 *
 * Two things differ from Stripe and shape everything here. Approval and payment
 * are separate calls, so an approved order still has to be captured. And a
 * notification carries no signature we can check ourselves: verifying it is an
 * API call back to PayPal, so there is no local equivalent of Stripe's HMAC.
 */
class PayPalGateway implements PaymentGateway
{

    public function __construct(
        private readonly Http $http,
        private readonly string $base,
        private readonly string $clientId,
        private readonly string $secret,
        private readonly string $webhookId,
    ) {}

    public function createSession(Order $order, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $response = $this->request()
            ->withHeaders(['PayPal-Request-Id' => 'order-'.$order->id])
            ->post("{$this->base}/v2/checkout/orders", [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => (string) $order->id,
                    'custom_id' => (string) $order->id,
                    'amount' => [
                        'currency_code' => strtoupper($order->currency),
                        'value' => Money::toDecimal($order->subtotal_cents),
                        'breakdown' => [
                            'item_total' => [
                                'currency_code' => strtoupper($order->currency),
                                'value' => Money::toDecimal($order->subtotal_cents),
                            ],
                        ],
                    ],
                    'items' => $order->items->map(fn ($item) => [
                        'name' => mb_substr($item->product->name, 0, 127),
                        'quantity' => '1',
                        'unit_amount' => [
                            'currency_code' => strtoupper($item->currency),
                            'value' => Money::toDecimal($item->price_cents),
                        ],
                    ])->all(),
                ]],
                // No `payment_source`, so PayPal offers every funding source it
                // has for the buyer — wallet or card — instead of insisting on
                // a PayPal balance.
                'application_context' => [
                    'user_action' => 'PAY_NOW',
                    'shipping_preference' => 'NO_SHIPPING',
                    'return_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('PayPal refused the order: '.$response->body());
        }

        $body = $response->json();
        $approval = collect($body['links'] ?? [])
            ->first(fn (array $link) => in_array($link['rel'] ?? '', ['payer-action', 'approve'], true));

        if ($approval === null) {
            throw new RuntimeException('PayPal returned no approval link.');
        }

        return new CheckoutSession($body['id'], $approval['href']);
    }

    public function parseEvent(string $payload, array $headers): PaymentEvent
    {
        $event = json_decode($payload, true);

        if (! is_array($event) || ! isset($event['id'], $event['event_type'])) {
            throw new RuntimeException('That is not a PayPal notification.');
        }

        if (! $this->verify($payload, $headers)) {
            throw new RuntimeException('PayPal did not vouch for that notification.');
        }

        return PayPalEvents::translate($event);
    }

    public function capture(string $sessionId): bool
    {
        $response = $this->request()
            // Retrying a capture must not take the money twice.
            ->withHeaders(['PayPal-Request-Id' => 'capture-'.$sessionId])
            // An empty body is not valid JSON, and PayPal refuses the call with
            // MALFORMED_REQUEST_JSON rather than reading the intent from the URL.
            ->withBody('{}', 'application/json')
            ->post("{$this->base}/v2/checkout/orders/{$sessionId}/capture");

        // Already captured is a success from our side, not a failure.
        if ($response->status() === 422 && str_contains($response->body(), 'ORDER_ALREADY_CAPTURED')) {
            return true;
        }

        return $response->successful();
    }

    public function sessionStatus(string $sessionId): ?string
    {
        $response = $this->request()->get("{$this->base}/v2/checkout/orders/{$sessionId}");

        if ($response->failed()) {
            return null;
        }

        // Spoken in the words the reconciler uses. `approved` is PayPal's alone:
        // the buyer agreed and nothing has taken the money yet.
        return match ($response->json('status')) {
            'COMPLETED' => 'paid',
            'APPROVED' => 'approved',
            'CREATED', 'SAVED', 'PAYER_ACTION_REQUIRED' => 'unpaid',
            default => null,
        };
    }

    /** @param array<string, string> $headers */
    private function verify(string $payload, array $headers): bool
    {
        $header = fn (string $name) => $headers[strtolower($name)] ?? $headers[$name] ?? null;

        $response = $this->request()->post("{$this->base}/v1/notifications/verify-webhook-signature", [
            'auth_algo' => $header('paypal-auth-algo'),
            'cert_url' => $header('paypal-cert-url'),
            'transmission_id' => $header('paypal-transmission-id'),
            'transmission_sig' => $header('paypal-transmission-sig'),
            'transmission_time' => $header('paypal-transmission-time'),
            'webhook_id' => $this->webhookId,
            'webhook_event' => json_decode($payload, true),
        ]);

        return $response->successful() && $response->json('verification_status') === 'SUCCESS';
    }

    private function request()
    {
        return $this->http->withToken($this->accessToken())->acceptJson()->asJson();
    }

    /**
     * Tokens last hours, so one is kept rather than fetched per call. The
     * credentials are part of the key: changing them has to invalidate the
     * token, or the next call is made as the previous merchant.
     */
    private function accessToken(): string
    {
        $key = 'paypal.access_token.'.substr(hash('sha256', $this->clientId.$this->base), 0, 16);

        return Cache::remember($key, now()->addMinutes(30), function (): string {
            $response = $this->http
                ->withBasicAuth($this->clientId, $this->secret)
                ->asForm()
                ->post("{$this->base}/v1/oauth2/token", ['grant_type' => 'client_credentials']);

            if ($response->failed()) {
                throw new RuntimeException('PayPal would not issue a token: '.$response->body());
            }

            return (string) $response->json('access_token');
        });
    }
}
