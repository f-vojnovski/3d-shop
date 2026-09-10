<?php

namespace App\Payments;

use App\Models\Order;

/**
 * Stands in for a provider in tests and when nothing is configured. It speaks
 * Stripe's wire format with a local secret, so verification and translation are
 * the real code: the signature is the security boundary, and a test that stubs
 * past it proves nothing.
 */
class FakeGateway implements PaymentGateway
{
    /** @var list<array{order_id: int, amount: int}> */
    public array $sessions = [];

    /** @var array<string, string> */
    public array $statuses = [];

    /** @var list<string> */
    public array $captured = [];

    private readonly StripeEvents $events;

    public function __construct(string $webhookSecret = 'whsec_test')
    {
        $this->events = new StripeEvents($webhookSecret);
    }

    public function createSession(Order $order, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $id = 'cs_test_'.$order->id;

        $this->sessions[] = [
            'order_id' => $order->id,
            'amount' => $order->items->sum('price_cents'),
        ];

        return new CheckoutSession($id, "https://checkout.test/{$id}");
    }

    public function parseEvent(string $payload, array $headers): PaymentEvent
    {
        return $this->events->parse($payload, $headers);
    }

    public function capture(string $sessionId): bool
    {
        $this->captured[] = $sessionId;

        return true;
    }

    public function sessionStatus(string $sessionId): ?string
    {
        return $this->statuses[$sessionId] ?? null;
    }
}
