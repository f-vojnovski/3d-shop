<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductFile;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class AttestationController extends BaseController
{
    private const ATTESTED = [
        ProductFile::KIND_PREVIEW_IMAGE,
        ProductFile::KIND_WIREFRAME,
    ];

    /**
     * What a buyer can check about a server-rendered image: which model it came
     * from, the camera it was taken with, and the exact renderer that drew it.
     */
    public function show(ProductFile $preview): array
    {
        abort_unless(in_array($preview->kind, self::ATTESTED, true), 404);

        // Public for a listing the caller can already see, and ids run in order.
        abort_unless(
            Product::whereKey($preview->product_id)->visibleTo(Auth::id())->exists(),
            404
        );

        $attestedSource = $preview->meta['source_checksum'] ?? null;
        $current = $preview->product->deliverables()->pluck('checksum')->all();
        $conversion = $preview->source?->meta['conversion'] ?? null;

        return [
            'product_id' => $preview->product_id,
            'image' => [
                'url' => Storage::disk($preview->disk)->url($preview->path),
                'sha256' => $preview->checksum,
                'bytes' => $preview->bytes,
                'angle' => $preview->sort,
                'pass' => $preview->kind === ProductFile::KIND_WIREFRAME ? 'wireframe' : 'shaded',
                'coverage' => $preview->meta['coverage'] ?? null,
                'rendered_at' => $preview->created_at,
            ],
            'camera' => $preview->meta['camera'] ?? null,
            'renderer' => $preview->meta['renderer'] ?? null,
            // Set only for the formats no browser opens: the pixels came from
            // the converted copy, not from the file named above.
            'converted' => $conversion,
            'source_model' => [
                'sha256' => $attestedSource,
                'still_on_sale' => $attestedSource !== null && in_array($attestedSource, $current, true),
                'format' => $preview->source?->format,
                'measured' => $preview->source?->facts(),
                'replaced_at' => $preview->source?->superseded_at,
                'replacement_note' => $preview->source?->replacement_note,
            ],
            'reproduce' => [
                'how' => $conversion === null
                    ? 'Build the render container at the pinned three.js version, feed it this model and camera, and compare the image sha256.'
                    : 'Build the render container at the pinned three.js and assimp versions, convert this model to glb and check it against converted.derived_sha256, then feed that and the camera to the renderer and compare the image sha256.',
                'command' => "php artisan render:verify {$preview->product_id}",
                'why_it_works' => 'Software rasterization makes output byte-identical across runs of the same renderer image.',
                'limit' => 'The server is restating its own record. Nothing here is signed, so this is internal consistency rather than third-party proof.',
            ],
        ];
    }
}
