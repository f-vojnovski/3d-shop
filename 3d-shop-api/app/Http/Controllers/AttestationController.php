<?php

namespace App\Http\Controllers;

use App\Models\ProductFile;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Storage;

class AttestationController extends BaseController
{
    /**
     * What a buyer can check about a server-rendered still: which model it came
     * from, the camera it was taken with, and the exact renderer that drew it.
     */
    public function show(ProductFile $preview): array
    {
        abort_unless($preview->kind === ProductFile::KIND_PREVIEW_IMAGE, 404);

        $attestedSource = $preview->meta['source_checksum'] ?? null;
        $current = $preview->product->deliverables()->pluck('checksum')->all();

        return [
            'product_id' => $preview->product_id,
            'image' => [
                'url' => Storage::disk($preview->disk)->url($preview->path),
                'sha256' => $preview->checksum,
                'bytes' => $preview->bytes,
                'angle' => $preview->sort,
                'coverage' => $preview->meta['coverage'] ?? null,
                'rendered_at' => $preview->created_at,
            ],
            'camera' => $preview->meta['camera'] ?? null,
            'renderer' => $preview->meta['renderer'] ?? null,
            'source_model' => [
                'sha256' => $attestedSource,
                'still_on_sale' => $attestedSource !== null && in_array($attestedSource, $current, true),
                'format' => $preview->source?->format,
                'measured' => $preview->source?->facts(),
            ],
            'reproduce' => [
                'how' => 'Build the render container at the pinned three.js version, feed it this model and camera, and compare the image sha256.',
                'command' => "php artisan render:verify {$preview->product_id}",
                'why_it_works' => 'Software rasterization makes output byte-identical across runs of the same renderer image.',
                'limit' => 'The server is restating its own record. Nothing here is signed, so this is internal consistency rather than third-party proof.',
            ],
        ];
    }
}
