<?php

namespace App\Http\Controllers;

use App\Jobs\HandlePaymentEvent;
use App\Models\WebhookEvent;
use App\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deliberately thin: verify, record, queue, answer. Fulfilment happens in a
 * worker, so a busy application still confirms receipt and the provider does
 * not retry a payment we already have.
 */
class PaymentWebhookController extends BaseController
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function handle(Request $request)
    {
        try {
            $event = $this->gateway->parseEvent(
                $request->getContent(),
                // Providers differ: Stripe signs with one header, PayPal sends
                // several and wants them verified against its own API.
                array_map(fn (array $values) => $values[0] ?? '', $request->headers->all())
            );
        } catch (Throwable $exception) {
            Log::warning('Rejected a payment webhook.', ['message' => $exception->getMessage()]);

            return response()->json(['message' => 'Signature could not be verified.'], 400);
        }

        // Providers retry, so seeing an event twice is normal. Inserting on
        // conflict keeps that race-safe and leaves no failed statement behind
        // to poison a surrounding transaction.
        $accepted = WebhookEvent::query()->insertOrIgnore([
            'id' => $event->id,
            'type' => $event->providerType,
            'gateway' => config('services.payments.gateway'),
            'received_at' => now(),
        ]);

        if ($accepted === 0) {
            return response()->json(['message' => 'Already received.']);
        }

        HandlePaymentEvent::dispatch($event);

        return response()->json(['message' => 'Received.']);
    }
}
