<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const REFUNDED = 'refunded';

    protected $fillable = [
        'buyer_id',
        'status',
        'currency',
        'subtotal_cents',
        'commission_cents',
        'gateway',
        'session_id',
        'payment_intent_id',
        'paid_at',
        'refunded_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_cents' => 'integer',
            'commission_cents' => 'integer',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [self::PAID, self::REFUNDED], true);
    }
}
