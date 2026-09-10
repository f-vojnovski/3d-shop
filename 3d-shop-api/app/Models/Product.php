<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    use HasFactory;

    public const PREVIEW_INTERACTIVE = 'interactive';
    public const PREVIEW_ATTESTED_STILLS = 'attested_stills';

    public const STATUS_OWNER = 'owner';
    public const STATUS_PURCHASED = 'purchased';
    public const STATUS_NOT_PURCHASED = 'not-purchased';

    protected $fillable = [
        'name',
        'description',
        'price_cents',
        'currency',
        'user_id',
        'preview_mode',
        'preview_status',
        'preview_error',
        'unlisted',
    ];

    // DB defaults are invisible on a freshly created instance.
    protected $attributes = [
        'currency' => 'USD',
        'preview_mode' => self::PREVIEW_INTERACTIVE,
        'unlisted' => false,
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'unlisted' => 'boolean',
        ];
    }

    public function files(): HasMany
    {
        return $this->hasMany(ProductFile::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function deliverables(): HasMany
    {
        return $this->files()->where('kind', ProductFile::KIND_DELIVERABLE);
    }

    public function previewImages(): HasMany
    {
        return $this->files()
            ->where('kind', ProductFile::KIND_PREVIEW_IMAGE)
            ->orderBy('sort');
    }

    public function thumbnail(): ?ProductFile
    {
        return $this->files->firstWhere('kind', ProductFile::KIND_THUMBNAIL);
    }

    public function deliverableFor(string $format): ?ProductFile
    {
        return $this->files->first(
            fn (ProductFile $file) => $file->kind === ProductFile::KIND_DELIVERABLE
                && $file->format === $format
        );
    }

    public function availableFormats(): array
    {
        return $this->files
            ->where('kind', ProductFile::KIND_DELIVERABLE)
            ->pluck('format')
            ->filter()
            ->values()
            ->all();
    }

    public function statusFor(?int $userId): string
    {
        if ($userId === null) {
            return self::STATUS_NOT_PURCHASED;
        }

        if ((int) $this->user_id === (int) $userId) {
            return self::STATUS_OWNER;
        }

        // Read from the relation only because `forViewer` filtered it to this user.
        $purchased = $this->relationLoaded('sales')
            ? $this->sales->contains(fn (Sale $sale) => (int) $sale->buyer_id === (int) $userId)
            : $this->sales()->where('buyer_id', $userId)->exists();

        return $purchased ? self::STATUS_PURCHASED : self::STATUS_NOT_PURCHASED;
    }

    public function isDownloadableBy(?int $userId): bool
    {
        return in_array(
            $this->statusFor($userId),
            [self::STATUS_OWNER, self::STATUS_PURCHASED],
            true
        );
    }

    public function scopeForViewer($query, ?int $userId)
    {
        return $userId === null
            ? $query
            : $query->with(['sales' => fn ($sales) => $sales->where('buyer_id', $userId)]);
    }

    public function servesInteractivePreview(): bool
    {
        return $this->preview_mode === self::PREVIEW_INTERACTIVE;
    }

    /**
     * Each deliverable renders on its own, so the product takes the least
     * finished of them: a buyer should not be told previews are ready while a
     * format is still rendering. Locked because every per-format job calls it.
     */
    public function refreshPreviewStatus(): void
    {
        DB::transaction(function () {
            $product = static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if ($product === null) {
                return;
            }

            $files = $product->deliverables()->get();
            $statuses = $files->map(fn (ProductFile $file) => $file->renderStatus());
            $failed = $files->first(fn (ProductFile $file) => $file->renderStatus() === 'failed');

            $status = match (true) {
                $statuses->isEmpty() => 'none',
                $statuses->contains('queued') || $statuses->contains('rendering') => 'rendering',
                $failed !== null => 'failed',
                $statuses->contains('ready') => 'ready',
                default => 'none',
            };

            $product->update([
                'preview_status' => $status,
                'preview_error' => $failed?->renderError(),
            ]);

            $this->setRawAttributes($product->getAttributes(), true);
        });
    }
}
