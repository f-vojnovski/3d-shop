<?php

namespace App\Jobs;

use App\Events\CustomViewDrawn;
use App\Models\CustomView;
use App\Models\ProductFile;
use App\Support\AnimateRunner;
use App\Support\RenderInput;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Draws one animation clip from a camera a viewer chose.
 *
 * A sibling of RenderCustomView rather than a branch inside it: that one feeds
 * the still path, and the still path is what an attested image is reproduced
 * from. This one carries a moving picture instead, and nothing else differs.
 */
class RenderCustomClip implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public int $uniqueFor = 1800;

    /** Fewer frames than a listing's clip: this is one viewer's passing look. */
    private const FRAMES = 20;

    public function __construct(public int $viewId) {}

    public function uniqueId(): string
    {
        return 'custom-clip:'.$this->viewId;
    }

    public function backoff(): array
    {
        return [20];
    }

    public function handle(AnimateRunner $runner): void
    {
        $view = CustomView::with('source.derived')->find($this->viewId);

        if ($view === null || $view->status === CustomView::READY || ! $view->isMoving()) {
            return;
        }

        $log = Log::channel('render')->withContext([
            'custom_view' => $view->id,
            'product_id' => $view->product_id,
            'user_id' => $view->user_id,
            'clip' => $view->clip,
            'pass' => $view->pass,
        ]);

        // A converted upload was never readable by the renderer.
        $opened = $view->source->derived()->current()->first() ?? $view->source;

        $scratch = storage_path('app/private/custom-clips/'.$view->id);
        $this->removeDirectory($scratch);
        @mkdir($scratch, 0775, true);

        // Every way out, not just the one that worked: the scratch holds a whole
        // copy of the model, and one that reliably fails is asked for again.
        try {
            $this->draw($view, $opened, $scratch, $runner, $log);
        } finally {
            $this->removeDirectory($scratch);
        }
    }

    private function draw(CustomView $view, ProductFile $opened, string $scratch, $runner, $log): void
    {
        $started = microtime(true);

        try {
            $modelPath = $scratch.DIRECTORY_SEPARATOR.'model';
            RenderInput::fetch($opened, $modelPath);

            $bundleDir = null;
            $entry = null;

            // Handed straight to the renderer, an archive is a zip header where
            // a mesh should be — and the viewer is charged for the attempt.
            if (RenderInput::isBundle($opened)) {
                $unpacked = RenderInput::unpack($modelPath, $scratch, $opened->format);

                if (is_string($unpacked)) {
                    $log->warning('Custom clip skipped.', ['reason' => $unpacked]);
                    $this->giveUp($view, 'That view could not be drawn.');

                    return;
                }

                [$bundleDir, $entry] = [$unpacked['dir'], $unpacked['entry']];
            }

            $result = $runner->run(
                (int) $view->clip,
                self::FRAMES,
                512,
                $view->camera,
                [$view->pass],
                $opened === $view->source
                    ? (string) ($view->source->meta['sniffed_format'] ?? $view->source->format)
                    : 'glb',
                $modelPath,
                $scratch,
                $bundleDir,
                $entry,
                ['user_id' => $view->user_id, 'product_file_id' => $view->product_file_id]
            );
        } catch (Throwable $exception) {
            $log->error('Custom clip threw.', ['message' => $exception->getMessage()]);
            $this->giveUp($view, 'That view could not be drawn.');

            return;
        }

        if (($result['status'] ?? 'failed') !== 'ok' || ($result['files'] ?? []) === []) {
            $log->warning('Custom clip failed.', ['result' => $result]);
            $this->giveUp($view, $result['reason'] ?? 'That view could not be drawn.');

            return;
        }

        $this->store($view, $scratch, $result['files'][0], $log, microtime(true) - $started);
        $this->announce($view, $log);
    }

    public function failed(?Throwable $exception): void
    {
        CustomView::whereKey($this->viewId)
            ->where('status', '!=', CustomView::READY)
            ->update([
                'status' => CustomView::FAILED,
                'failure_reason' => 'That view could not be drawn.',
            ]);
    }

    /** @param  array{pass?: string, file?: string}  $drawn */
    private function store(CustomView $view, string $scratch, array $drawn, $log, float $seconds): void
    {
        $name = $drawn['file'] ?? '';

        // The container parses untrusted geometry, so what it names its output
        // is input: only the one file it was asked for is read.
        if ($name !== 'clip-'.$view->pass.'.webp') {
            $log->warning('Clip harness named an unexpected file.', ['file' => $name]);
            $this->giveUp($view, 'That view could not be drawn.');

            return;
        }

        $bytes = (string) file_get_contents(
            $scratch.DIRECTORY_SEPARATOR.'out'.DIRECTORY_SEPARATOR.$name
        );
        $path = sprintf(
            'custom_views/%d/%d/%s-%s-clip%d.webp',
            $view->user_id,
            $view->product_file_id,
            substr($view->fingerprint, 0, 16),
            $view->pass,
            $view->clip
        );

        Storage::disk('public')->put($path, $bytes);

        $view->update([
            'status' => CustomView::READY,
            'disk' => 'public',
            'path' => $path,
            'bytes' => strlen($bytes),
            'failure_reason' => null,
        ]);

        $log->info('Custom clip drawn.', [
            'seconds' => round($seconds, 2),
            'bytes' => strlen($bytes),
            'path' => $path,
        ]);
    }

    private function giveUp(CustomView $view, string $reason): void
    {
        $view->update(['status' => CustomView::FAILED, 'failure_reason' => $reason]);
        $this->announce($view, Log::channel('render'));
    }

    /** Best effort: a broadcaster that is down must not fail a drawn view. */
    private function announce(CustomView $view, $log): void
    {
        try {
            event(CustomViewDrawn::for($view->refresh()));
        } catch (Throwable $exception) {
            $log->warning('Could not announce the view.', ['message' => $exception->getMessage()]);
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
