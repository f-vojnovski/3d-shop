<?php

namespace App\Payments;

use App\Models\Order;

interface PaymentGateway
{
    /** Hosted checkout for one order: where to send the buyer, and its id. */
    public function createSession(Order $order, string $successUrl, string $cancelUrl): CheckoutSession;

    /**
     * Verifies the notification and translates it. Throws if it cannot be
     * trusted; returns an IGNORED event if it can be trusted but means nothing
     * to us.
     *
     * @param  array<string, string>  $headers
     */
    public function parseEvent(string $payload, array $headers): PaymentEvent;

    /**
     * Takes the money the buyer approved. Stripe's hosted checkout has already
     * done this by the time it tells us; PayPal has not.
     */
    public function capture(string $sessionId): bool;

    /** What the provider currently believes about a session we may have lost. */
    public function sessionStatus(string $sessionId): ?string;
}
