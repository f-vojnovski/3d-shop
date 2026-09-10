<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'seller_id',
        'price_cents',
        'currency',
        'commission_cents',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'commission_cents' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** What the seller is owed: the price less the platform's cut. */
    public function sellerEarnsCents(): int
    {
        return $this->price_cents - $this->commission_cents;
    }
}
