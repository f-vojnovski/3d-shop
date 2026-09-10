<?php

namespace App\Jobs;

use App\Events\PreviewRenderFinished;
use App\Models\Product;
use App\Models\ProductFile;
use App\Support\RenderInput;
use App\Support\RenderRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
     * Above $timeout, so a worker killed mid-render stops blocking re-dispatch
     * shortly after the job would have been abandoned anyway.
     */
    public int $uniqueFor = 700;

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

    public function handle(RenderRunner $runner): void
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
            'triangles' => $scan->triangles,
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
            $result = $runner->run(
                RenderInput::request($product, $source, $scan),
                $modelPath,
                $scratch
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
        $this->settle($product, $source, 'ready', $this->blankNotice($result));

        $this->announce($product->refresh(), $log);

        $log->info('Render complete.', [
            'seconds' => $seconds,
            'renderer_seconds' => $result['seconds'] ?? null,
            'images' => count($result['images']),
            'blank' => $result['blank'] ?? [],
            'triangles' => $result['triangles'] ?? null,
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
        $public = Storage::disk('public');
        $source->stills()->each(fn (ProductFile $old) => $old->delete());

        foreach ($result['images'] as $image) {
            // The container parses untrusted geometry, so what it names its
            // output is input: only files it was supposed to write are read.
            $index = $image['index'] ?? null;

            if (! is_int($index) || ! preg_match('/^angle-\d+\.png$/', (string) ($image['file'] ?? ''))) {
                Log::channel('render')->warning('Renderer named an unexpected file.', [
                    'product_id' => $product->id,
                    'file' => $image['file'] ?? null,
                    'index' => $index,
                ]);

                continue;
            }

            $bytes = (string) file_get_contents(
                $scratch.DIRECTORY_SEPARATOR.'out'.DIRECTORY_SEPARATOR.$image['file']
            );
            $path = 'preview_images/'.uniqid().'.png';
            $public->put($path, $bytes);

            $product->files()->create([
                'source_file_id' => $source->id,
                'kind' => ProductFile::KIND_PREVIEW_IMAGE,
                'format' => 'png',
                'disk' => 'public',
                'path' => $path,
                'sort' => $index,
                'bytes' => strlen($bytes),
                'checksum' => hash('sha256', $bytes),
                'meta' => [
                    'source_format' => $source->format,
                    'camera' => $source->angles()[$index] ?? null,
                    'coverage' => $image['coverage'] ?? null,
                    'renderer' => $result['renderer'] ?? null,
                    'source_checksum' => $source->checksum,
                ],
            ]);
        }
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
