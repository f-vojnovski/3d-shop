<?php

namespace App\Payments;

use Stripe\Webhook;

/**
 * Verifies a Stripe notification and says what it means. Shared by the real
 * gateway and by the fake, which speaks Stripe's wire format with a local
 * secret — so tests exercise this translation rather than stepping around it.
 */
class StripeEvents
{
    public function __construct(private readonly string $webhookSecret) {}

    /** @param array<string, string> $headers */
    public function parse(string $payload, array $headers): PaymentEvent
    {
        $signature = $headers['stripe-signature'] ?? $headers['Stripe-Signature'] ?? '';
        $event = Webhook::constructEvent($payload, $signature, $this->webhookSecret)->toArray();
        $object = $event['data']['object'] ?? [];

        return new PaymentEvent(
            id: $event['id'],
            kind: $this->kind($event['type'], $object),
            providerType: $event['type'],
            orderReference: $object['metadata']['order_id'] ?? $object['client_reference_id'] ?? null,
            sessionId: str_starts_with((string) ($object['id'] ?? ''), 'cs_') ? $object['id'] : null,
            paymentId: $object['payment_intent'] ?? null,
            amountCents: isset($object['amount_total']) ? (int) $object['amount_total'] : null,
            currency: $object['currency'] ?? null,
            reason: $this->reason($event['type'], $object),
        );
    }

    private function kind(string $type, array $object): string
    {
        return match ($type) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' =>
                ($object['payment_status'] ?? 'paid') === 'paid'
                    ? PaymentEvent::PAID
                    : PaymentEvent::IGNORED,
            'checkout.session.expired' => PaymentEvent::EXPIRED,
            'payment_intent.payment_failed' => PaymentEvent::FAILED,
            'charge.refunded' => $this->refundKind($object),
            'charge.dispute.created' => PaymentEvent::DISPUTED,
            default => PaymentEvent::IGNORED,
        };
    }

    private function refundKind(array $object): string
    {
        $charged = (int) ($object['amount'] ?? 0);
        $refunded = (int) ($object['amount_refunded'] ?? $charged);

        return $charged > 0 && $refunded < $charged
            ? PaymentEvent::PARTIALLY_REFUNDED
            : PaymentEvent::REFUNDED;
    }

    private function reason(string $type, array $object): ?string
    {
        return match ($type) {
            'payment_intent.payment_failed' => $object['last_payment_error']['message'] ?? 'The payment failed.',
            'checkout.session.expired' => 'The checkout session expired.',
            'charge.dispute.created' => 'Disputed: '.($object['reason'] ?? 'reason not given'),
            'charge.refunded' => $this->refundKind($object) === PaymentEvent::PARTIALLY_REFUNDED
                ? sprintf(
                    'Partially refunded: %d of %d.',
                    (int) ($object['amount_refunded'] ?? 0),
                    (int) ($object['amount'] ?? 0)
                )
                : 'Refunded in full.',
            default => null,
        };
    }
}
