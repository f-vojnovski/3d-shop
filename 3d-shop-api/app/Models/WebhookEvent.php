<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['id', 'type', 'gateway', 'received_at', 'handled_at'];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'handled_at' => 'datetime',
        ];
    }
}
