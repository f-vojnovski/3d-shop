<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\WebhookEvent;
use App\Payments\Checkout;
use App\Payments\PaymentEvent;
use App\Payments\PaymentGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * One fulfilment path for every provider: the gateway has already translated
 * the notification into this project's vocabulary before it gets here.
 */
class HandlePaymentEvent implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public function __construct(public PaymentEvent $event) {}

    public function handle(Checkout $checkout, PaymentGateway $gateway): void
    {
        $order = $this->order();

        if ($order === null) {
            // An event we cannot place is not a failure: it may belong to
            // another environment sharing the same provider account.
            Log::info('Payment event matched no order.', [
                'event' => $this->event->id,
                'type' => $this->event->providerType,
            ]);
            $this->done();

            return;
        }

        match ($this->event->kind) {
            PaymentEvent::APPROVED => $this->approved($gateway, $order),
            PaymentEvent::PAID => $this->paid($checkout, $order),
            PaymentEvent::EXPIRED => $this->settle($order, Order::EXPIRED),
            PaymentEvent::FAILED => $this->settle($order, Order::FAILED),
            PaymentEvent::REFUNDED => $checkout->refund($order),
            PaymentEvent::PARTIALLY_REFUNDED, PaymentEvent::DISPUTED => $this->note($order),
            default => Log::info('Ignored a payment event.', ['type' => $this->event->providerType]),
        };

        $this->done();
    }

    /**
     * The buyer agreed but the money has not moved. Capturing here rather than
     * on the return page means a buyer who closes the tab still gets what they
     * paid for.
     */
    private function approved(PaymentGateway $gateway, Order $order): void
    {
        if ($order->isSettled() || $this->event->sessionId === null) {
            return;
        }

        // Left pending on purpose. A capture that failed once may succeed on
        // the retry, and marking it failed strands a payment the buyer has
        // already approved somewhere the reconciler will not look.
        if (! $gateway->capture($this->event->sessionId)) {
            throw new RuntimeException("Could not capture order {$order->id}; leaving it pending.");
        }
    }

    private function paid(Checkout $checkout, Order $order): void
    {
        if ($this->event->currency !== null
            && strcasecmp($this->event->currency, $order->currency) !== 0) {
            $this->settle($order, Order::FAILED, 'The currency charged did not match the order.');

            return;
        }

        // The snapshot is what we agreed to charge. A disagreement means this
        // is not the payment this order opened.
        if ($this->event->amountCents !== null
            && $this->event->amountCents !== $order->subtotal_cents) {
            $this->settle($order, Order::FAILED, 'The amount charged did not match the order.');
            Log::warning('Amount mismatch on a payment event.', [
                'order' => $order->id,
                'charged' => $this->event->amountCents,
                'expected' => $order->subtotal_cents,
            ]);

            return;
        }

        if ($this->event->paymentId !== null) {
            $order->update(['payment_intent_id' => $this->event->paymentId]);
        }

        $checkout->fulfil($order);
    }

    /**
     * Recorded, not acted on. A partial refund is usually a goodwill
     * adjustment, and whether a dispute should cost someone their files is a
     * decision for a person.
     */
    private function note(Order $order): void
    {
        $order->update(['failure_reason' => $this->event->reason]);

        Log::warning('A payment needs a human.', [
            'order' => $order->id,
            'kind' => $this->event->kind,
            'reason' => $this->event->reason,
        ]);
    }

    private function settle(Order $order, string $status, ?string $reason = null): void
    {
        if ($order->isSettled()) {
            return;
        }

        $order->update([
            'status' => $status,
            'failure_reason' => $reason ?? $this->event->reason,
        ]);
    }

    private function order(): ?Order
    {
        if ($this->event->orderReference !== null) {
            return Order::with('items')->find($this->event->orderReference);
        }

        if ($this->event->sessionId !== null) {
            return Order::with('items')->firstWhere('session_id', $this->event->sessionId);
        }

        // A refund names the payment, not the session it came from.
        return $this->event->paymentId === null
            ? null
            : Order::with('items')->firstWhere('payment_intent_id', $this->event->paymentId);
    }

    private function done(): void
    {
        WebhookEvent::whereKey($this->event->id)->update(['handled_at' => now()]);
    }
}
