<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductFile extends Model
{
    use HasFactory;

    public const KIND_DELIVERABLE = 'deliverable';
    public const KIND_PREVIEW_IMAGE = 'preview_image';
    public const KIND_THUMBNAIL = 'thumbnail';

    protected $fillable = [
        'product_id',
        'kind',
        'format',
        'disk',
        'path',
        'sort',
        'bytes',
        'checksum',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'sort' => 'integer',
            'bytes' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
