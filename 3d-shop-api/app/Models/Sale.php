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
        'seller_id',
        'product_id',
        'price'
    ];
}
