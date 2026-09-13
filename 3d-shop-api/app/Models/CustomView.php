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

    public const CHECKER = 'checker';

    public const NORMALS = 'normals';

    public const MATCAP = 'matcap';

    public const QUEUED = 'queued';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const PASSES = [self::SHADED, self::WIREFRAME, self::CHECKER, self::NORMALS, self::MATCAP];

    /** A checker on a model with no UVs is one flat colour, which says nothing. */
    public const NEEDS_UVS = [self::CHECKER];

    public const INFLUENCE = 'influence';

    public const BONES = 'bones';

    /**
     * What a moving view can be painted as. Narrower than the still passes: a
     * wireframe of a walking model is unreadable, and a checker says nothing
     * about motion.
     */
    public const CLIP_PASSES = [self::SHADED, self::INFLUENCE, self::BONES];

    /** Long enough to look at, short enough not to become a gallery. */
    public const LIFETIME_HOURS = 2;

    /**
     * How many a person may ask for in a day. Each starts a container that runs
     * for minutes, and the fingerprint that stops one camera being drawn twice
     * does nothing to stop a thousand almost-identical ones.
     */
    public const DAILY_LIMIT = 60;

    /**
     * And how much those may cost, in the unit a cloud charges for: cpus held,
     * times seconds held.
     *
     * A count alone is not a budget. The same request is four seconds on a small
     * model and ninety on a large one, so sixty of the heaviest costs about ten
     * times sixty of the lightest. Two limits because they guard two different
     * things - the count protects the queue, this protects the bill.
     *
     * Deliberately loose. This is a demonstration, and a limit tight enough to
     * be realistic is tight enough to get in the way of showing the thing work.
     * The shape is the point; the number would be argued down from real traffic.
     */
    public const DAILY_BUDGET_VCPU_SECONDS = 7200;

    protected $fillable = [
        'user_id',
        'product_id',
        'product_file_id',
        'pass',
        'clip',
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
            'clip' => 'integer',
            'bytes' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /** Asking for the same camera and pass twice returns the first answer. */
    public static function fingerprintOf(array $camera, string $pass, ?int $clip = null): string
    {
        return hash('sha256', json_encode([
            'position' => array_map(fn ($value) => round((float) $value, 4), $camera['position']),
            'target' => array_map(fn ($value) => round((float) $value, 4), $camera['target']),
            'up' => array_map(fn ($value) => round((float) $value, 4), $camera['up'] ?? [0, 1, 0]),
            'fov' => round((float) $camera['fov'], 2),
            'pass' => $pass,
            'clip' => $clip,
        ]));
    }

    public function isMoving(): bool
    {
        return $this->clip !== null;
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
