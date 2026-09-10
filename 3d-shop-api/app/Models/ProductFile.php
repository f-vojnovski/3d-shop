<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductFile extends Model
{
    use HasFactory;

    public const KIND_DELIVERABLE = 'deliverable';
    public const KIND_PREVIEW_IMAGE = 'preview_image';
    public const KIND_THUMBNAIL = 'thumbnail';

    protected $fillable = [
        'product_id',
        'source_file_id',
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

    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_file_id');
    }

    public function stills(): HasMany
    {
        return $this->hasMany(self::class, 'source_file_id')
            ->where('kind', self::KIND_PREVIEW_IMAGE)
            ->orderBy('sort');
    }

    public function angles(): array
    {
        return $this->meta['angles'] ?? [];
    }

    public function renderStatus(): string
    {
        return $this->meta['render']['status'] ?? 'none';
    }

    public function renderError(): ?string
    {
        return $this->meta['render']['error'] ?? null;
    }

    public function withMeta(array $values): void
    {
        $this->update(['meta' => array_replace($this->meta ?? [], $values)]);
    }
}
