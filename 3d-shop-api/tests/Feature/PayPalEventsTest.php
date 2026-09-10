<?php

namespace Tests\Feature;

use App\Payments\Money;
use App\Payments\PaymentEvent;
use App\Payments\PayPalEvents;
use PHPUnit\Framework\TestCase;

/**
 * PayPal counts in decimal strings and splits approval from payment, so the
 * translation is where a second provider either fits the one fulfilment path
 * or does not.
 */
class PayPalEventsTest extends TestCase
{
    public function test_a_capture_completing_means_paid(): void
    {
        $event = PayPalEvents::translate($this->event('PAYMENT.CAPTURE.COMPLETED', [
            'id' => '3C679366HH908993F',
            'custom_id' => '42',
            'amount' => ['currency_code' => 'USD', 'value' => '149.00'],
            'supplementary_data' => ['related_ids' => ['order_id' => '5O190127TN364715T']],
        ]));

        $this->assertSame(PaymentEvent::PAID, $event->kind);
        $this->assertSame('42', $event->orderReference);
        $this->assertSame(14900, $event->amountCents);
        $this->assertSame('USD', $event->currency);
        $this->assertSame('3C679366HH908993F', $event->paymentId);
        $this->assertSame('5O190127TN364715T', $event->sessionId);
    }

    /**
     * The distinction Stripe does not have: the buyer has agreed but no money
     * has moved, so this asks for a capture rather than granting anything.
     */
    public function test_an_approved_order_is_not_a_payment(): void
    {
        $event = PayPalEvents::translate($this->event('CHECKOUT.ORDER.APPROVED', [
            'id' => '5O190127TN364715T',
            'purchase_units' => [[
                'custom_id' => '42',
                'amount' => ['currency_code' => 'USD', 'value' => '149.00'],
            ]],
        ]));

        $this->assertSame(PaymentEvent::APPROVED, $event->kind);
        $this->assertSame('42', $event->orderReference);
        $this->assertSame('5O190127TN364715T', $event->sessionId);
    }

    public function test_a_denied_capture_is_a_failure_with_a_reason(): void
    {
        $event = PayPalEvents::translate($this->event('PAYMENT.CAPTURE.DENIED', [
            'id' => '3C6',
            'custom_id' => '42',
        ]));

        $this->assertSame(PaymentEvent::FAILED, $event->kind);
        $this->assertSame('PayPal declined the payment.', $event->reason);
    }

    public function test_a_voided_order_is_treated_as_expired(): void
    {
        $event = PayPalEvents::translate($this->event('CHECKOUT.ORDER.VOIDED', [
            'id' => '5O1',
            'purchase_units' => [['custom_id' => '42']],
        ]));

        $this->assertSame(PaymentEvent::EXPIRED, $event->kind);
    }

    public function test_a_full_refund_is_a_refund(): void
    {
        $event = PayPalEvents::translate($this->event('PAYMENT.CAPTURE.REFUNDED', [
            'id' => 'REF1',
            'custom_id' => '42',
            'amount' => ['currency_code' => 'USD', 'value' => '149.00'],
            'seller_payable_breakdown' => [
                'gross_amount' => ['currency_code' => 'USD', 'value' => '149.00'],
                'total_refunded_amount' => ['currency_code' => 'USD', 'value' => '149.00'],
            ],
        ]));

        $this->assertSame(PaymentEvent::REFUNDED, $event->kind);
        $this->assertSame('Refunded in full.', $event->reason);
    }

    public function test_a_partial_refund_is_told_apart_from_a_full_one(): void
    {
        $event = PayPalEvents::translate($this->event('PAYMENT.CAPTURE.REFUNDED', [
            'id' => 'REF1',
            'custom_id' => '42',
            'amount' => ['currency_code' => 'USD', 'value' => '50.00'],
            'seller_payable_breakdown' => [
                'gross_amount' => ['currency_code' => 'USD', 'value' => '149.00'],
                'total_refunded_amount' => ['currency_code' => 'USD', 'value' => '50.00'],
            ],
        ]));

        $this->assertSame(PaymentEvent::PARTIALLY_REFUNDED, $event->kind);
        $this->assertStringContainsString('50.00 of 149.00', (string) $event->reason);
    }

    /** Guessing wrong towards "full" costs a seller a sale; the reverse costs a buyer their money. */
    public function test_a_refund_without_a_breakdown_is_treated_as_full(): void
    {
        $event = PayPalEvents::translate($this->event('PAYMENT.CAPTURE.REFUNDED', [
            'id' => 'REF1',
            'custom_id' => '42',
        ]));

        $this->assertSame(PaymentEvent::REFUNDED, $event->kind);
    }

    public function test_a_dispute_carries_its_reason(): void
    {
        $event = PayPalEvents::translate($this->event('CUSTOMER.DISPUTE.CREATED', [
            'id' => 'PP-D-1',
            'custom_id' => '42',
            'reason' => 'MERCHANDISE_OR_SERVICE_NOT_RECEIVED',
        ]));

        $this->assertSame(PaymentEvent::DISPUTED, $event->kind);
        $this->assertStringContainsString('NOT_RECEIVED', (string) $event->reason);
    }

    public function test_an_event_we_do_not_act_on_is_ignored_rather_than_guessed(): void
    {
        $event = PayPalEvents::translate($this->event('BILLING.SUBSCRIPTION.CREATED', ['id' => 'I-1']));

        $this->assertSame(PaymentEvent::IGNORED, $event->kind);
        $this->assertSame('BILLING.SUBSCRIPTION.CREATED', $event->providerType);
    }

    public function test_the_order_id_can_be_found_wherever_paypal_puts_it(): void
    {
        $onCapture = PayPalEvents::translate($this->event('PAYMENT.CAPTURE.COMPLETED', [
            'id' => 'C1', 'custom_id' => '7',
        ]));
        $onOrder = PayPalEvents::translate($this->event('CHECKOUT.ORDER.APPROVED', [
            'id' => 'O1', 'purchase_units' => [['reference_id' => '8']],
        ]));

        $this->assertSame('7', $onCapture->orderReference);
        $this->assertSame('8', $onOrder->orderReference);
    }

    /**
     * Every amount crosses this boundary, and going via a float loses money on
     * values a computer cannot hold exactly.
     */
    public function test_money_survives_the_round_trip(): void
    {
        foreach ([0, 1, 9, 10, 99, 100, 4999, 14900, 999999, 100000000] as $cents) {
            $decimal = Money::toDecimal($cents);

            $this->assertSame($cents, Money::toCents($decimal), "broke on {$cents}");
        }

        $this->assertSame('0.07', Money::toDecimal(7));
        $this->assertSame('149.00', Money::toDecimal(14900));
        $this->assertSame('1.10', Money::toDecimal(110));
    }

    public function test_money_reads_what_paypal_actually_sends(): void
    {
        $this->assertSame(14900, Money::toCents('149.00'));
        $this->assertSame(14900, Money::toCents('149'));
        $this->assertSame(1490, Money::toCents('14.9'));
        $this->assertSame(7, Money::toCents('0.07'));
        $this->assertSame(0, Money::toCents('0.00'));
        // Currencies with more precision than we keep are truncated, not rounded up.
        $this->assertSame(149, Money::toCents('1.499'));
    }

    private function event(string $type, array $resource): array
    {
        return [
            'id' => 'WH-'.substr(md5($type), 0, 12),
            'event_type' => $type,
            'resource' => $resource,
        ];
    }
}
