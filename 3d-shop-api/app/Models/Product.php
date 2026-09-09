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

    protected $fillable = [
        'name',
        'description',
        'price_cents',
        'currency',
        'obj_file_path',
        'gltf_file_path',
        'thumbnail_path',
        'user_id',
        'preview_mode',
        'unlisted',
    ];

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
}
