<?php

namespace App\Jobs;

use App\Events\PreviewRenderFinished;
use App\Models\Product;
use App\Models\ProductFile;
use App\Support\MeshFacts;
use App\Support\ModelConverter;
use App\Support\RenderInput;
use App\Support\RenderRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class RenderProductPreviews implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 600;

    /**
     * Must outlive the job's whole life, retries included: 2 attempts of 600s
     * plus a 30s backoff is 1230s, and a lock expiring mid-retry would let a
     * duplicate render start alongside the one already running.
     */
    public int $uniqueFor = 1320;

    public function __construct(
        public int $productId,
        public string $format,
    ) {}

    public function uniqueId(): string
    {
        return $this->productId.':'.$this->format;
    }

    public function backoff(): array
    {
        return [30];
    }

    public function handle(RenderRunner $runner, ModelConverter $converter): void
    {
        $product = Product::with('files')->find($this->productId);

        if ($product === null) {
            return;
        }

        $log = Log::channel('render')->withContext([
            'product_id' => $product->id,
            'format' => $this->format,
            'attempt' => $this->attempts(),
            'worker' => gethostname().'/'.getmypid(),
        ]);

        $source = $product->files
            ->where('kind', ProductFile::KIND_DELIVERABLE)
            ->firstWhere('format', $this->format);

        if ($source === null) {
            $log->info('That format is no longer attached, nothing to render.');

            return;
        }

        if ($source->angles() === []) {
            $this->settle($product, $source, 'none', null);
            $log->info('No angles for this format, nothing to render.');

            return;
        }

        $scan = RenderInput::scanOf($source);

        $log->info('Admission check.', [
            'format' => $scan->format,
            'bytes' => $scan->bytes,
            'faces' => $scan->faces,
            'disk' => $source->disk,
        ]);

        if ($rejection = $scan->rejection()) {
            $this->giveUp($product, $log, $rejection);

            return;
        }

        $this->settle($product, $source, 'rendering', null);

        $scratch = storage_path('app/private/render-scratch/'.$product->id.'-'.$this->format);
        $this->removeDirectory($scratch);
        @mkdir($scratch, 0775, true);

        $startedAt = microtime(true);

        try {
            $modelPath = $this->fetchModel($source, $scratch, $log);
            $rendered = null;
            $bundleDir = null;
            $entry = null;

            if (RenderInput::isBundle($source)) {
                $unpacked = RenderInput::unpack($modelPath, $scratch);

                if (is_string($unpacked)) {
                    $this->failOrRetry($product, $log, $unpacked, retryable: false);

                    return;
                }

                $log->info('Bundle unpacked.', $unpacked);
                [$bundleDir, $entry] = [$unpacked['dir'], $unpacked['entry']];
            }

            if ($bundleDir === null && ModelConverter::needsConverting($scan->format)) {
                $conversion = $converter->toGlb($modelPath, $scratch);

                if (($conversion['status'] ?? 'failed') !== 'ok') {
                    $log->error('Conversion failed.', ['result' => $conversion]);
                    $this->failOrRetry(
                        $product,
                        $log,
                        $conversion['reason'] ?? 'The file could not be converted.',
                        retryable: (bool) ($conversion['retryable'] ?? false)
                    );

                    return;
                }

                $rendered = 'glb';
                $derived = $this->recordConversion($product, $source, $conversion, $conversion['path'], $log);

                // The file verify renders, and one the host wrote itself rather
                // than a mount racing the container that filled it.
                $modelPath = $scratch.DIRECTORY_SEPARATOR.'model.glb';
                RenderInput::fetch($derived, $modelPath);
            }

            $result = $runner->run(
                RenderInput::request($product, $source, $scan, $rendered, $entry),
                $modelPath,
                $scratch,
                $bundleDir
            );
        } catch (Throwable $exception) {
            $log->error('Render threw.', ['message' => $exception->getMessage()]);
            $this->failOrRetry($product, $log, 'Rendering failed unexpectedly.', retryable: true);

            return;
        }

        $seconds = round(microtime(true) - $startedAt, 2);

        if (($result['status'] ?? 'failed') !== 'ok') {
            // Scratch is kept on failure: result.json is the only diagnostic.
            $log->error('Render failed.', [
                'seconds' => $seconds,
                'scratch' => $scratch,
                'result' => $result,
            ]);

            $this->failOrRetry(
                $product,
                $log,
                $result['reason'] ?? 'Rendering failed.',
                retryable: (bool) ($result['retryable'] ?? false)
            );

            return;
        }

        $this->storeImages($product, $source, $scratch, $result);
        $this->recordMissing($source, $result);
        $this->settle($product, $source, 'ready', $this->blankNotice($result));

        $this->announce($product->refresh(), $log);

        $log->info('Render complete.', [
            'seconds' => $seconds,
            'renderer_seconds' => $result['seconds'] ?? null,
            'images' => count($result['images']),
            'blank' => $result['blank'] ?? [],
            'triangles' => $result['triangles'] ?? null,
            'wireframes' => $result['wireframes'] ?? null,
            'missing' => $result['missing'] ?? [],
        ]);

        $this->removeDirectory($scratch);
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('render')->error('Render job did not complete.', [
            'product_id' => $this->productId,
            'exception' => $exception?->getMessage(),
        ]);

        $product = Product::find($this->productId);

        if ($product === null) {
            return;
        }

        $source = $product->deliverables()->where('format', $this->format)->first();

        if ($source !== null) {
            $source->withMeta([
                'render' => [
                    'status' => 'failed',
                    'error' => 'Preview rendering did not complete after retrying.',
                ],
            ]);
        }

        $product->refreshPreviewStatus();

        $this->announce($product->refresh(), Log::channel('render'));
    }

    private function failOrRetry(Product $product, $log, string $reason, bool $retryable): void
    {
        if ($retryable && $this->attempts() < $this->tries) {
            $log->warning('Retryable failure, releasing for another attempt.', [
                'reason' => $reason,
                'attempts_used' => $this->attempts(),
            ]);

            // Left as 'rendering' so the UI does not flap to failed and back.
            throw new RuntimeException('Render failed, retrying: '.$reason);
        }

        $this->giveUp($product, $log, $reason);
    }

    private function giveUp(Product $product, $log, string $reason): void
    {
        $log->warning('Giving up on render.', ['reason' => $reason]);

        $source = $product->deliverables()->where('format', $this->format)->first();

        if ($source !== null) {
            $this->settle($product, $source, 'failed', $reason);
        }

        $this->announce($product->refresh(), $log);
    }

    /**
     * The seller's notice is best effort: a broadcaster that is down must not
     * turn a finished render into a failed job.
     */
    private function announce(Product $product, $log): void
    {
        try {
            event(PreviewRenderFinished::for($product, $this->format));
        } catch (Throwable $exception) {
            $log->warning('Could not announce the render.', [
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /** Records this format's outcome, then recomputes the product's own status. */
    private function settle(Product $product, ProductFile $source, string $status, ?string $error): void
    {
        $source->withMeta(['render' => ['status' => $status, 'error' => $error]]);
        $product->refreshPreviewStatus();
    }

    private function blankNotice(array $result): ?string
    {
        $blank = $result['blank'] ?? [];

        return $blank === [] ? null : sprintf(
            '%d of %d angles rendered blank and were discarded.',
            count($blank),
            count($blank) + count($result['images'])
        );
    }

    /**
     * Keeps and measures what the renderer opened. The buyer still downloads
     * the file they uploaded, so the record names both and the tool between.
     */
    private function recordConversion(
        Product $product,
        ProductFile $source,
        array $conversion,
        string $path,
        $log
    ): ProductFile {
        $source->derived()->each(fn (ProductFile $old) => $old->delete());

        $checksum = (string) hash_file('sha256', $path);
        $stored = 'derived/'.$product->id.'-'.$source->format.'-'.substr($checksum, 0, 12).'.glb';
        $handle = fopen($path, 'rb');
        Storage::disk($source->disk)->put($stored, $handle);
        fclose($handle);

        $derived = $product->files()->create([
            'source_file_id' => $source->id,
            'kind' => ProductFile::KIND_DERIVED,
            'format' => 'glb',
            'disk' => $source->disk,
            'path' => $stored,
            'sort' => 0,
            'bytes' => (int) filesize($path),
            'checksum' => $checksum,
            'meta' => ['converted_from' => $source->checksum, 'tool' => $conversion['tool'] ?? null],
        ]);

        $facts = MeshFacts::of($path, 'glb')->toArray();

        $source->withMeta([
            'facts' => $facts,
            'conversion' => [
                'tool' => $conversion['tool'] ?? null,
                'to' => 'glb',
                'derived_sha256' => $checksum,
            ],
        ]);

        $log->info('Converted for rendering.', [
            'tool' => $conversion['tool'] ?? null,
            'derived_bytes' => filesize($path),
            'faces' => $facts['faces'] ?? null,
        ]);

        return $derived;
    }

    /**
     * Textures the model named and the bundle did not hold. Recorded rather
     * than hidden: the render is honest about the file, and the file is
     * missing something.
     */
    private function recordMissing(ProductFile $source, array $result): void
    {
        $missing = array_values(array_map(
            fn (string $path) => ltrim(str_replace('/bundle/', '', $path), '/'),
            $result['missing'] ?? []
        ));

        $source->withMeta(['missing' => $missing === [] ? null : $missing]);
    }

    private function fetchModel(ProductFile $source, string $scratch, $log): string
    {
        $target = $scratch.DIRECTORY_SEPARATOR.'model';
        $startedAt = microtime(true);

        $bytes = RenderInput::fetch($source, $target);

        $log->debug('Model fetched to scratch.', [
            'bytes' => $bytes,
            'seconds' => round(microtime(true) - $startedAt, 2),
        ]);

        return $target;
    }

    private function storeImages(
        Product $product,
        ProductFile $source,
        string $scratch,
        array $result
    ): void {
        $source->stills()->each(fn (ProductFile $old) => $old->delete());
        $source->wireframes()->each(fn (ProductFile $old) => $old->delete());

        foreach ($result['images'] as $image) {
            $index = $image['index'] ?? null;

            if (! is_int($index) || ! $this->wasAskedFor($product, $image['file'] ?? null, 'angle', $index)) {
                continue;
            }

            $meta = [
                'source_format' => $source->format,
                'camera' => $source->angles()[$index] ?? null,
                'coverage' => $image['coverage'] ?? null,
                'renderer' => $result['renderer'] ?? null,
                'source_checksum' => $source->checksum,
            ];

            $this->storeImage(
                $product,
                $source,
                $scratch,
                $image['file'],
                ProductFile::KIND_PREVIEW_IMAGE,
                $index,
                $meta
            );

            $outline = $image['wireframe'] ?? null;

            if (! is_array($outline)
                || ! $this->wasAskedFor($product, $outline['file'] ?? null, 'wireframe', $index)) {
                continue;
            }

            $this->storeImage(
                $product,
                $source,
                $scratch,
                $outline['file'],
                ProductFile::KIND_WIREFRAME,
                $index,
                array_replace($meta, ['coverage' => $outline['coverage'] ?? null])
            );
        }

        $this->adoptThumbnail($product);
    }

    /**
     * The container parses untrusted geometry, so what it names its output is
     * input: only files it was supposed to write are read.
     */
    private function wasAskedFor(Product $product, mixed $file, string $prefix, int $index): bool
    {
        if ($file === "{$prefix}-{$index}.png") {
            return true;
        }

        Log::channel('render')->warning('Renderer named an unexpected file.', [
            'product_id' => $product->id,
            'file' => $file,
            'expected' => "{$prefix}-{$index}.png",
        ]);

        return false;
    }

    private function storeImage(
        Product $product,
        ProductFile $source,
        string $scratch,
        string $file,
        string $kind,
        int $index,
        array $meta
    ): void {
        $bytes = (string) file_get_contents(
            $scratch.DIRECTORY_SEPARATOR.'out'.DIRECTORY_SEPARATOR.$file
        );
        $path = 'preview_images/'.uniqid().'.png';
        Storage::disk('public')->put($path, $bytes);

        $product->files()->create([
            'source_file_id' => $source->id,
            'kind' => $kind,
            'format' => 'png',
            'disk' => 'public',
            'path' => $path,
            'sort' => $index,
            'bytes' => strlen($bytes),
            'checksum' => hash('sha256', $bytes),
            'meta' => $meta,
        ]);
    }

    /**
     * A seller who framed angles but uploaded no thumbnail has nothing to show
     * on a listing card until something renders. The first rendered view stands
     * in. Locked because the formats render concurrently and both would
     * otherwise claim the slot.
     */
    private function adoptThumbnail(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $fresh = Product::query()->whereKey($product->getKey())->lockForUpdate()->first();

            if ($fresh === null || $fresh->thumbnail() !== null) {
                return;
            }

            $still = $fresh->files()
                ->where('kind', ProductFile::KIND_PREVIEW_IMAGE)
                ->orderBy('sort')
                ->first();

            if ($still === null) {
                return;
            }

            // Points at the same stored object rather than copying it: both
            // rows keep it alive, and files:prune only deletes what nothing
            // references.
            $fresh->files()->create([
                'kind' => ProductFile::KIND_THUMBNAIL,
                'disk' => $still->disk,
                'path' => $still->path,
                'sort' => 0,
                'bytes' => $still->bytes,
                'checksum' => $still->checksum,
                'meta' => ['from' => 'standard_view'],
            ]);
        });
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
}
