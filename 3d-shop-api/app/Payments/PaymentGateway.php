<?php

namespace App\Payments;

use App\Models\Order;

interface PaymentGateway
{
    /**
     * The gateway could not be asked. Distinct from null, which is the gateway
     * answering that it has never heard of the session: one is a reason to ask
     * again later, the other is a reason to give up on the order.
     */
    public const UNREACHABLE = 'unreachable';

    /** Recorded against the order and the event, so a row says what handled it. */
    public function name(): string;

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
     * Takes the money the buyer approved. A hosted checkout may have done this
     * by the time it sends the notification; PayPal has not.
     */
    public function capture(string $sessionId): bool;

    /** What the provider currently believes about a session we may have lost. */
    public function sessionStatus(string $sessionId): ?string;

    /** The smallest amount this provider will accept. Zero means no floor. */
    public function minimumChargeCents(): int;
}
