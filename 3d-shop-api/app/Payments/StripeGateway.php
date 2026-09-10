<?php

namespace App\Payments;

use App\Models\Order;
use RuntimeException;
use Stripe\StripeClient;

class StripeGateway implements PaymentGateway
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly StripeEvents $events,
    ) {}

    public function createSession(Order $order, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => $order->items->map(fn ($item) => [
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($item->currency),
                    'unit_amount' => $item->price_cents,
                    'product_data' => ['name' => $item->product->name],
                ],
            ])->all(),
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $order->id,
            'metadata' => ['order_id' => (string) $order->id],
        ], [
            // Retrying the same order must not create a second session.
            'idempotency_key' => 'order-'.$order->id,
        ]);

        return new CheckoutSession($session->id, (string) $session->url);
    }

    public function parseEvent(string $payload, array $headers): PaymentEvent
    {
        return $this->events->parse($payload, $headers);
    }

    /** Stripe captures on its own checkout page. */
    public function capture(string $sessionId): bool
    {
        return true;
    }

    public function sessionStatus(string $sessionId): ?string
    {
        try {
            return $this->stripe->checkout->sessions->retrieve($sessionId)->payment_status;
        } catch (RuntimeException) {
            return null;
        }
    }
}
