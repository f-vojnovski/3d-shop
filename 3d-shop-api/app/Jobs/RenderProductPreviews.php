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

    /** Two renders of one product would share a scratch directory. */
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

        // Recorded at upload, so rejecting costs no transfer from object storage.
        $scan = new MeshPrescan(
            bytes: (int) $source->bytes,
            triangles: (int) ($source->meta['triangles'] ?? 0),
            format: (string) ($source->meta['sniffed_format'] ?? 'unknown'),
        );

        $log->info('Admission check.', [
            'format' => $scan->format,
            'bytes' => $scan->bytes,
            'triangles' => $scan->triangles,
            'disk' => $source->disk,
        ]);

        if ($rejection = $scan->rejection()) {
            $this->reject($product, $log, $rejection);

            return;
        }

        $product->update(['preview_status' => 'rendering', 'preview_error' => null]);

        $scratch = storage_path('app/private/render-scratch/'.$product->id);
        $this->removeDirectory($scratch);
        @mkdir($scratch, 0775, true);

        try {
            $modelPath = $this->fetchModel($source, $scratch, $log);

            $startedAt = microtime(true);
            $result = $runner->run(
                $this->renderRequest($product, $source, $scan),
                $modelPath,
                $scratch
            );
            $seconds = round(microtime(true) - $startedAt, 2);

            if (($result['status'] ?? 'failed') !== 'ok') {
                $log->error('Render failed.', [
                    'seconds' => $seconds,
                    'scratch' => $scratch,
                    'result' => $result,
                ]);
                $this->reject($product, $log, $result['reason'] ?? 'Rendering failed.');

                return;
            }

            $this->storeImages($product, $scratch, $result);

            $log->info('Render complete.', [
                'seconds' => $seconds,
                'renderer_seconds' => $result['seconds'] ?? null,
                'images' => count($result['images']),
                'blank' => $result['blank'] ?? [],
                'triangles' => $result['triangles'] ?? null,
            ]);

            $this->removeDirectory($scratch);
        } catch (Throwable $exception) {
            $log->error('Render threw.', ['message' => $exception->getMessage()]);
            $this->reject($product, $log, 'Rendering failed unexpectedly.');
        }
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

    /**
     * Streams the model out of storage onto local scratch. This is the whole
     * reason a worker needs no shared filesystem: it pulls what it needs.
     */
    private function fetchModel(ProductFile $source, string $scratch, $log): string
    {
        $target = $scratch.DIRECTORY_SEPARATOR.'model';
        $startedAt = microtime(true);

        $read = Storage::disk($source->disk)->readStream($source->path);
        $write = fopen($target, 'wb');
        stream_copy_to_stream($read, $write);
        fclose($write);
        fclose($read);

        $log->debug('Model fetched to scratch.', [
            'bytes' => filesize($target),
            'seconds' => round(microtime(true) - $startedAt, 2),
        ]);

        return $target;
    }

    private function storeImages(Product $product, string $scratch, array $result): void
    {
        $public = Storage::disk('public');
        $product->previewImages()->delete();

        $sourceChecksum = $product->files
            ->firstWhere('kind', ProductFile::KIND_DELIVERABLE)?->checksum;

        foreach ($result['images'] as $image) {
            $bytes = (string) file_get_contents(
                $scratch.DIRECTORY_SEPARATOR.'out'.DIRECTORY_SEPARATOR.$image['file']
            );
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
                    'coverage' => $image['coverage'] ?? null,
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
                : sprintf(
                    '%d of %d angles rendered blank and were discarded.',
                    count($blank),
                    count($blank) + count($result['images'])
                ),
        ]);
    }

    private function reject(Product $product, $log, string $reason): void
    {
        $log->warning('Render rejected.', ['reason' => $reason]);
        $product->update(['preview_status' => 'failed', 'preview_error' => $reason]);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
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
