<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ProductFile extends Model
{
    use HasFactory;

    public const KIND_DELIVERABLE = 'deliverable';
    public const KIND_PREVIEW_IMAGE = 'preview_image';

    public const KIND_SELLER_IMAGE = 'seller_image';
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

    /** Measured from the file at upload, never supplied by the seller. */
    public function facts(): ?array
    {
        return $this->meta['facts'] ?? null;
    }

    public function renderStatus(): string
    {
        return $this->meta['render']['status'] ?? 'none';
    }

    public function renderError(): ?string
    {
        return $this->meta['render']['error'] ?? null;
    }

    /**
     * Merges against the row as it is now, not as this instance remembers it: a
     * render settling after the seller edited the angles used to write the old
     * angles back over the new ones.
     */
    public function withMeta(array $values): void
    {
        DB::transaction(function () use ($values) {
            $fresh = static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                return;
            }

            $fresh->update(['meta' => array_replace($fresh->meta ?? [], $values)]);
            $this->setRawAttributes($fresh->getAttributes(), true);
        });
    }
}
