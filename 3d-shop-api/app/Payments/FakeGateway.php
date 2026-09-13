<?php

namespace App\Payments;

use App\Models\Order;

/**
 * Stands in for a provider in tests and when nothing is configured. Its
 * webhook is signed with a local secret and parsed by the same code path a
 * live provider goes through.
 */
class FakeGateway implements PaymentGateway
{
    /** @var list<array{order_id: int, amount: int}> */
    public array $sessions = [];

    /** @var array<string, string> */
    public array $statuses = [];

    /** @var list<string> */
    public array $captured = [];

    private readonly SignedEvents $events;

    public function __construct(string $webhookSecret = 'whsec_test')
    {
        $this->events = new SignedEvents($webhookSecret);
    }

    public function name(): string
    {
        return 'fake';
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

    public function minimumChargeCents(): int
    {
        return 0;
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
