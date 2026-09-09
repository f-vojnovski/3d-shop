<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductFile;
use App\Support\MeshPrescan;
use App\Support\RenderRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RenderProductPreviews implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 600;

    /** Two renders of one product would share a staging directory. */
    public int $uniqueFor = 1800;

    public function __construct(public int $productId) {}

    public function uniqueId(): string
    {
        return (string) $this->productId;
    }

    public function handle(RenderRunner $runner): void
    {
        $product = Product::with('files')->find($this->productId);

        if ($product === null) {
            return;
        }

        $log = Log::channel('render')->withContext(['product_id' => $product->id]);

        if (($product->preview_angles ?? []) === []) {
            $product->update(['preview_status' => 'none', 'preview_error' => null]);
            $log->info('No angles requested, nothing to render.');

            return;
        }

        $source = $product->files->firstWhere('kind', ProductFile::KIND_DELIVERABLE);

        if ($source === null) {
            $this->reject($product, $log, 'This product has no model file to render.');

            return;
        }

        $models = Storage::disk($source->disk);
        $scan = MeshPrescan::of($models->path($source->path));

        $log->info('Prescan complete.', [
            'format' => $scan->format,
            'bytes' => $scan->bytes,
            'triangles' => $scan->triangles,
        ]);

        if ($rejection = $scan->rejection()) {
            $this->reject($product, $log, $rejection);

            return;
        }

        $product->update(['preview_status' => 'rendering', 'preview_error' => null]);

        $staging = "render/{$product->id}";
        Storage::disk('models')->deleteDirectory($staging);

        $startedAt = microtime(true);
        $result = $runner->run(
            $this->renderRequest($product, $source, $scan),
            $models->path($source->path),
            $staging
        );
        $seconds = round(microtime(true) - $startedAt, 2);

        if (($result['status'] ?? 'failed') !== 'ok') {
            // Staging is kept on failure: result.json is the only diagnostic.
            $log->error('Render failed.', [
                'seconds' => $seconds,
                'staging' => $staging,
                'result' => $result,
            ]);
            $this->reject($product, $log, $result['reason'] ?? 'Rendering failed.');

            return;
        }

        $this->storeImages($product, $staging, $result);

        $log->info('Render complete.', [
            'seconds' => $seconds,
            'renderer_seconds' => $result['seconds'] ?? null,
            'images' => count($result['images']),
            'blank' => $result['blank'] ?? [],
            'triangles' => $result['triangles'] ?? null,
        ]);

        Storage::disk('models')->deleteDirectory($staging);
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('render')->error('Render job did not complete.', [
            'product_id' => $this->productId,
            'exception' => $exception?->getMessage(),
        ]);

        Product::whereKey($this->productId)->update([
            'preview_status' => 'failed',
            'preview_error' => 'Preview rendering did not complete.',
        ]);
    }

    private function storeImages(Product $product, string $staging, array $result): void
    {
        $models = Storage::disk('models');
        $public = Storage::disk('public');

        $product->previewImages()->delete();
        $sourceChecksum = $product->files
            ->firstWhere('kind', ProductFile::KIND_DELIVERABLE)?->checksum;

        foreach ($result['images'] as $image) {
            $bytes = $models->get("{$staging}/out/{$image['file']}");
            $path = 'preview_images/'.uniqid().'.png';
            $public->put($path, $bytes);

            $product->files()->create([
                'kind' => ProductFile::KIND_PREVIEW_IMAGE,
                'format' => 'png',
                'disk' => 'public',
                'path' => $path,
                'sort' => $image['index'],
                'bytes' => strlen($bytes),
                'checksum' => hash('sha256', $bytes),
                'meta' => [
                    'camera' => $product->preview_angles[$image['index']] ?? null,
                    'renderer' => $result['renderer'] ?? null,
                    'source_checksum' => $sourceChecksum,
                ],
            ]);
        }

        $blank = $result['blank'] ?? [];

        $product->update([
            'preview_status' => 'ready',
            'preview_error' => $blank === []
                ? null
                : sprintf('%d of %d angles rendered blank and were discarded.',
                    count($blank), count($blank) + count($result['images'])),
        ]);
    }

    private function reject(Product $product, $log, string $reason): void
    {
        $log->warning('Render rejected.', ['reason' => $reason]);
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
            'output' => ['width' => 1200, 'height' => 900],
        ];
    }
}
