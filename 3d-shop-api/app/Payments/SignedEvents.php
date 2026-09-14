<?php

namespace App\Payments;

use RuntimeException;

/**
 * The fake provider's wire format: a JSON envelope signed with a shared secret.
 *
 * Verification is real code rather than a stub. The signature is the security
 * boundary on the webhook route, and a test that steps around it proves nothing
 * about the route a live provider posts to.
 */
class SignedEvents
{
    /** How stale a signature may be. A replay from yesterday is not a payment. */
    private const TOLERANCE_SECONDS = 300;

    public function __construct(private readonly string $webhookSecret) {}

    /** @param array<string, string> $headers */
    public function parse(string $payload, array $headers): PaymentEvent
    {
        $this->verify($payload, $headers['x-signature'] ?? $headers['X-Signature'] ?? '');

        $event = json_decode($payload, true);

        if (! is_array($event) || ! isset($event['id'], $event['type'])) {
            throw new RuntimeException('The payload is not an event.');
        }

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

    /**
     * `t=<unix>,v1=<hmac sha256 of "t.body">`. The timestamp is signed with the
     * body, so it cannot be moved forward to refresh an old capture.
     */
    private function verify(string $payload, string $header): void
    {
        $parts = [];

        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = (int) ($parts['t'] ?? 0);
        $given = (string) ($parts['v1'] ?? '');

        if ($timestamp <= 0 || $given === '') {
            throw new RuntimeException('The payload carries no signature.');
        }

        if (abs(time() - $timestamp) > self::TOLERANCE_SECONDS) {
            throw new RuntimeException('The signature is too old.');
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->webhookSecret);

        if (! hash_equals($expected, $given)) {
            throw new RuntimeException('The signature does not match the body.');
        }
    }

    private function kind(string $type, array $object): string
    {
        return match ($type) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => ($object['payment_status'] ?? 'paid') === 'paid'
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
