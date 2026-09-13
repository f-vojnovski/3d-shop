<?php

namespace App\Http\Controllers;

use App\Jobs\HandlePaymentEvent;
use App\Models\WebhookEvent;
use App\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
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
                // Providers differ: some sign with one header, PayPal sends several
                // and wants them verified against its own API.
                array_map(fn (array $values) => $values[0] ?? '', $request->headers->all())
            );
        } catch (Throwable $exception) {
            Log::warning('Rejected a payment webhook.', ['message' => $exception->getMessage()]);

            return response()->json(['message' => 'Signature could not be verified.'], 400);
        }

        // Providers retry, so seeing an event twice is normal; inserting on
        // conflict keeps that race-safe. Both writes in one transaction, because
        // the row is what answers "already received" to the next retry, and one
        // written without its queued work would turn away every retry of an
        // event nobody handled.
        $accepted = DB::transaction(function () use ($event) {
            $accepted = WebhookEvent::query()->insertOrIgnore([
                'id' => $event->id,
                'type' => $event->providerType,
                'gateway' => $this->gateway->name(),
                'received_at' => now(),
            ]);

            if ($accepted !== 0) {
                HandlePaymentEvent::dispatch($event);
            }

            return $accepted;
        });

        if ($accepted === 0) {
            return response()->json(['message' => 'Already received.']);
        }

        return response()->json(['message' => 'Received.']);
    }
}
