<?php

namespace App\Payments;

/**
 * PayPal's notification vocabulary, translated into this project's.
 *
 * The order id travels in `custom_id`, which PayPal echoes back on the capture
 * as well as the order, so a refund arriving days later can still be placed.
 */
class PayPalEvents
{
    public static function translate(array $event): PaymentEvent
    {
        $resource = $event['resource'] ?? [];
        $type = (string) $event['event_type'];
        $amount = self::amount($resource);

        return new PaymentEvent(
            id: (string) $event['id'],
            kind: self::kind($type, $resource),
            providerType: $type,
            orderReference: self::reference($resource),
            sessionId: self::sessionId($type, $resource),
            paymentId: $resource['id'] ?? null,
            amountCents: $amount['cents'],
            currency: $amount['currency'],
            reason: self::reason($type, $resource),
        );
    }

    private static function kind(string $type, array $resource): string
    {
        return match ($type) {
            'CHECKOUT.ORDER.APPROVED' => PaymentEvent::APPROVED,
            'PAYMENT.CAPTURE.COMPLETED' => PaymentEvent::PAID,
            'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.REVERSED' => PaymentEvent::FAILED,
            'CHECKOUT.ORDER.VOIDED' => PaymentEvent::EXPIRED,
            'PAYMENT.CAPTURE.REFUNDED' => self::refundKind($resource),
            'CUSTOMER.DISPUTE.CREATED' => PaymentEvent::DISPUTED,
            default => PaymentEvent::IGNORED,
        };
    }

    /**
     * A refund names what it returned; the capture it came from names what was
     * taken. Anything less than the whole amount is a partial refund.
     */
    private static function refundKind(array $resource): string
    {
        $refunded = $resource['seller_payable_breakdown']['total_refunded_amount']['value'] ?? null;
        $captured = $resource['seller_payable_breakdown']['gross_amount']['value'] ?? null;

        // Without both figures there is nothing to compare, and treating an
        // unclear refund as full is the safer way to be wrong.
        if ($refunded === null || $captured === null) {
            return PaymentEvent::REFUNDED;
        }

        return Money::toCents((string) $refunded) < Money::toCents((string) $captured)
            ? PaymentEvent::PARTIALLY_REFUNDED
            : PaymentEvent::REFUNDED;
    }

    /** @return array{cents: int|null, currency: string|null} */
    private static function amount(array $resource): array
    {
        $amount = $resource['amount']
            ?? $resource['purchase_units'][0]['amount']
            ?? $resource['seller_receivable_breakdown']['gross_amount']
            ?? null;

        if (! is_array($amount) || ! isset($amount['value'])) {
            return ['cents' => null, 'currency' => null];
        }

        return [
            'cents' => Money::toCents((string) $amount['value']),
            'currency' => $amount['currency_code'] ?? null,
        ];
    }

    private static function reference(array $resource): ?string
    {
        return $resource['custom_id']
            ?? $resource['purchase_units'][0]['custom_id']
            ?? $resource['purchase_units'][0]['reference_id']
            ?? $resource['supplementary_data']['related_ids']['order_id']
            ?? null;
    }

    /**
     * The id we can capture against. On an order event that is the resource
     * itself; on a capture it is the order the capture belongs to.
     */
    private static function sessionId(string $type, array $resource): ?string
    {
        if (str_starts_with($type, 'CHECKOUT.ORDER.')) {
            return $resource['id'] ?? null;
        }

        return $resource['supplementary_data']['related_ids']['order_id'] ?? null;
    }

    private static function reason(string $type, array $resource): ?string
    {
        return match ($type) {
            'PAYMENT.CAPTURE.DENIED' => 'PayPal declined the payment.',
            'PAYMENT.CAPTURE.REVERSED' => 'The payment was reversed.',
            'CHECKOUT.ORDER.VOIDED' => 'The order was voided before payment.',
            'CUSTOMER.DISPUTE.CREATED' => 'Disputed: '.($resource['reason'] ?? 'reason not given'),
            'PAYMENT.CAPTURE.REFUNDED' => self::refundKind($resource) === PaymentEvent::PARTIALLY_REFUNDED
                ? 'Partially refunded: '.($resource['seller_payable_breakdown']['total_refunded_amount']['value'] ?? '?')
                    .' of '.($resource['seller_payable_breakdown']['gross_amount']['value'] ?? '?')
                : 'Refunded in full.',
            default => null,
        };
    }
}
