<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductFile;
use App\Support\MeshPrescan;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class RenderProductPreviews implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 600;

    public function __construct(public int $productId) {}

    public function handle(): void
    {
        $product = Product::with('files')->find($this->productId);

        if ($product === null) {
            return;
        }

        $angles = $product->preview_angles ?? [];

        if ($angles === []) {
            $product->update(['preview_status' => 'none', 'preview_error' => null]);

            return;
        }

        $source = $product->files
            ->firstWhere('kind', ProductFile::KIND_DELIVERABLE);

        if ($source === null) {
            $this->reject($product, 'This product has no model file to render.');

            return;
        }

        $scan = MeshPrescan::of(Storage::disk($source->disk)->path($source->path));

        if ($rejection = $scan->rejection()) {
            $this->reject($product, $rejection);

            return;
        }

        $product->update(['preview_status' => 'rendering', 'preview_error' => null]);

        // The render request is deliberately plain JSON: the container that
        // consumes it knows nothing about Laravel, so the renderer can be
        // replaced or reimplemented without touching this side.
        $request = $this->renderRequest($product, $source, $scan);

        Storage::disk('models')->put(
            "render-requests/{$product->id}.json",
            json_encode($request, JSON_PRETTY_PRINT)
        );
    }

    public function failed(?\Throwable $exception): void
    {
        Product::whereKey($this->productId)->update([
            'preview_status' => 'failed',
            'preview_error' => 'Preview rendering did not complete.',
        ]);
    }

    private function reject(Product $product, string $reason): void
    {
        $product->update(['preview_status' => 'failed', 'preview_error' => $reason]);
    }

    private function renderRequest(Product $product, ProductFile $source, MeshPrescan $scan): array
    {
        return [
            'product_id' => $product->id,
            'source' => [
                'path' => $source->path,
                'format' => $scan->format,
                'checksum' => $source->checksum,
                'bytes' => $scan->bytes,
                'triangles' => $scan->triangles,
            ],
            'angles' => $product->preview_angles,
            'output' => [
                'width' => 1200,
                'height' => 900,
            ],
        ];
    }
}
