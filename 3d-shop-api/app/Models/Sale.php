<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $table='sales';

    use HasFactory;
    protected $fillable = [
        'buyer_id',
        'product_id',
        'order_item_id',
        'price_cents',
        'currency',
    ];

    protected $attributes = [
        'currency' => 'USD',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
        ];
    }
}
