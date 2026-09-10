<?php

namespace App\Payments;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Checkout
{
    public function __construct(private readonly int $commissionBps) {}

    /**
     * Prices a cart from the database and records what will be charged. The
     * request contributes product ids and nothing else.
     *
     * @param  list<int>  $productIds
     */
    public function open(int $buyerId, array $productIds): Order
    {
        $products = Product::whereIn('id', $productIds)->get();

        if ($products->isEmpty()) {
            throw ValidationException::withMessages(['products' => 'There is nothing to buy.']);
        }

        if ($products->count() !== count(array_unique($productIds))) {
            throw ValidationException::withMessages(['products' => 'One of those products no longer exists.']);
        }

        $currencies = $products->pluck('currency')->unique();

        if ($currencies->count() > 1) {
            throw ValidationException::withMessages([
                'products' => 'These products are priced in different currencies. Buy them separately.',
            ]);
        }

        foreach ($products as $product) {
            $this->guard($product, $buyerId);
        }

        return DB::transaction(function () use ($buyerId, $products, $currencies) {
            $order = Order::create([
                'buyer_id' => $buyerId,
                'status' => Order::PENDING,
                'currency' => $currencies->first(),
                'subtotal_cents' => $products->sum('price_cents'),
                'commission_cents' => $products->sum(
                    fn (Product $product) => $this->commissionOn($product->price_cents)
                ),
            ]);

            foreach ($products as $product) {
                $order->items()->create([
                    'product_id' => $product->id,
                    'seller_id' => $product->user_id,
                    'price_cents' => $product->price_cents,
                    'currency' => $product->currency,
                    'commission_cents' => $this->commissionOn($product->price_cents),
                ]);
            }

            return $order->load('items.product');
        });
    }

    /**
     * Grants what an order paid for. Runs from the webhook and from the
     * reconciler, so it has to be safe to call twice.
     */
    public function fulfil(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $fresh = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if ($fresh === null || $fresh->isSettled()) {
                return;
            }

            foreach ($fresh->items as $item) {
                Sale::firstOrCreate(
                    ['buyer_id' => $fresh->buyer_id, 'product_id' => $item->product_id],
                    [
                        'order_item_id' => $item->id,
                        'price_cents' => $item->price_cents,
                        'currency' => $item->currency,
                    ]
                );
            }

            $fresh->update(['status' => Order::PAID, 'paid_at' => now()]);
        });
    }

    /** A refund takes the download back: the buyer no longer paid for it. */
    public function refund(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $fresh = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if ($fresh === null || $fresh->status === Order::REFUNDED) {
                return;
            }

            Sale::whereIn('order_item_id', $fresh->items->pluck('id'))->delete();
            $fresh->update(['status' => Order::REFUNDED, 'refunded_at' => now()]);
        });
    }

    public function commissionOn(int $priceCents): int
    {
        return intdiv($priceCents * $this->commissionBps, 10000);
    }

    private function guard(Product $product, int $buyerId): void
    {
        if ($product->unlisted) {
            throw ValidationException::withMessages([
                'products' => "{$product->name} is no longer for sale.",
            ]);
        }

        if ((int) $product->user_id === $buyerId) {
            throw ValidationException::withMessages([
                'products' => "You already own {$product->name}.",
            ]);
        }

        $owned = Sale::where('buyer_id', $buyerId)->where('product_id', $product->id)->exists();

        if ($owned) {
            throw ValidationException::withMessages([
                'products' => "You have already purchased {$product->name}.",
            ]);
        }
    }
}
