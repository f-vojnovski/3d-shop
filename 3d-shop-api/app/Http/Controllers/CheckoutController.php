<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Payments\Checkout;
use App\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;

class CheckoutController extends BaseController
{
    public function __construct(
        private readonly Checkout $checkout,
        private readonly PaymentGateway $gateway,
    ) {}

    public function session(Request $request)
    {
        $fields = $request->validate([
            'products' => 'required|array|min:1|max:32',
            'products.*.id' => 'required|integer|exists:products,id',
        ]);

        $buyerId = (int) Auth::user()->getAuthIdentifier();
        $order = $this->checkout->open($buyerId, array_column($fields['products'], 'id'));

        // Nothing to charge: a free product, or a build with payments switched
        // off. Both grant straight away rather than opening a session Stripe
        // would refuse.
        if ($order->subtotal_cents === 0 || ! config('services.payments.enabled')) {
            $this->checkout->fulfil($order);

            return $this->describe($order->fresh(['items']));
        }

        $session = $this->gateway->createSession(
            $order,
            config('app.frontend_url').'/checkout/complete?order='.$order->id,
            config('app.frontend_url').'/checkout?order='.$order->id
        );

        $order->update(['session_id' => $session->id]);

        return $this->describe($order->fresh(['items']), $session->url);
    }

    /** The return page polls this: the webhook usually lands after it. */
    public function show(Request $request, $id)
    {
        $order = Order::with('items')->findOrFail($id);

        if ((int) $order->buyer_id !== (int) Auth::user()->getAuthIdentifier()) {
            abort(403, 'That is not your order.');
        }

        return $this->describe($order);
    }

    private function describe(Order $order, ?string $url = null): array
    {
        return array_filter([
            'id' => $order->id,
            'status' => $order->status,
            'currency' => $order->currency,
            // The buyer is shown what the server priced, never the cart's total.
            'subtotal_cents' => $order->subtotal_cents,
            'items' => $order->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'price_cents' => $item->price_cents,
            ])->all(),
            'checkout_url' => $url,
        ], fn ($value) => $value !== null);
    }
}
