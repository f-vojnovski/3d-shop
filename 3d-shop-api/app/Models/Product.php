<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

        return $this->sales()->where('buyer_id', $userId)->exists()
            ? self::STATUS_PURCHASED
            : self::STATUS_NOT_PURCHASED;
    }

    public function isDownloadableBy(?int $userId): bool
    {
        return in_array(
            $this->statusFor($userId),
            [self::STATUS_OWNER, self::STATUS_PURCHASED],
            true
        );
    }

    public function servesInteractivePreview(): bool
    {
        return $this->preview_mode === self::PREVIEW_INTERACTIVE;
    }
}
