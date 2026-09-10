<?php

namespace App\Payments;

/**
 * A payment event in this project's vocabulary rather than a provider's.
 *
 * Stripe says `checkout.session.completed` and counts in integer cents; PayPal
 * says `PAYMENT.CAPTURE.COMPLETED` and counts in decimal strings, and needs the
 * payment captured after the buyer approves it. Translating at the edge keeps
 * one fulfilment path instead of one per provider.
 */
class PaymentEvent
{
    /** The buyer paid. Grant what the order bought. */
    public const PAID = 'paid';

    /** Approved but not yet captured; PayPal needs a second call. */
    public const APPROVED = 'approved';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const REFUNDED = 'refunded';

    public const PARTIALLY_REFUNDED = 'partially_refunded';

    public const DISPUTED = 'disputed';

    /** Something we do not act on. */
    public const IGNORED = 'ignored';

    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $providerType,
        public readonly ?string $orderReference = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $paymentId = null,
        public readonly ?int $amountCents = null,
        public readonly ?string $currency = null,
        public readonly ?string $reason = null,
    ) {}
}
