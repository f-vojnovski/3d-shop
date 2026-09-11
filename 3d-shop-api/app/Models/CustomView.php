<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A view a signed-in viewer asked for, of a model they may not own. Nothing
 * here joins the product's record: it carries no attestation and it expires.
 */
class CustomView extends Model
{
    public const SHADED = 'shaded';
    public const WIREFRAME = 'wireframe';

    public const QUEUED = 'queued';
    public const READY = 'ready';
    public const FAILED = 'failed';

    public const PASSES = [self::SHADED, self::WIREFRAME];

    /** Long enough to look at, short enough not to become a gallery. */
    public const LIFETIME_HOURS = 2;

    protected $fillable = [
        'user_id',
        'product_id',
        'product_file_id',
        'pass',
        'status',
        'camera',
        'fingerprint',
        'disk',
        'path',
        'bytes',
        'failure_reason',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'camera' => 'array',
            'bytes' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /** Asking for the same camera and pass twice returns the first answer. */
    public static function fingerprintOf(array $camera, string $pass): string
    {
        return hash('sha256', json_encode([
            'position' => array_map(fn ($value) => round((float) $value, 4), $camera['position']),
            'target' => array_map(fn ($value) => round((float) $value, 4), $camera['target']),
            'up' => array_map(fn ($value) => round((float) $value, 4), $camera['up'] ?? [0, 1, 0]),
            'fov' => round((float) $camera['fov'], 2),
            'pass' => $pass,
        ]));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ProductFile::class, 'product_file_id');
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', now());
    }

    public function url(): ?string
    {
        return $this->path === null
            ? null
            : Storage::disk($this->disk ?? 'public')->url($this->path);
    }
}
