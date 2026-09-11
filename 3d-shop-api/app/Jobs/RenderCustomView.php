<?php

namespace App\Jobs;

use App\Events\CustomViewDrawn;
use App\Models\CustomView;
use App\Support\RenderInput;
use App\Support\RenderRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Draws one camera a viewer chose. Reuses the render tier untouched: the only
 * differences from an attested still are that the camera came from the request
 * and the result carries no attestation.
 */
class RenderCustomView implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 600;
    public int $uniqueFor = 1320;

    public function __construct(public int $viewId) {}

    public function uniqueId(): string
    {
        return 'custom-view:'.$this->viewId;
    }

    public function backoff(): array
    {
        return [20];
    }

    public function handle(RenderRunner $runner): void
    {
        $view = CustomView::with('source.derived')->find($this->viewId);

        if ($view === null || $view->status === CustomView::READY) {
            return;
        }

        $log = Log::channel('render')->withContext([
            'custom_view' => $view->id,
            'product_id' => $view->product_id,
            'user_id' => $view->user_id,
            'pass' => $view->pass,
        ]);

        // A converted upload was never readable by the renderer.
        $opened = $view->source->derived()->current()->first() ?? $view->source;

        $scratch = storage_path('app/private/custom-views/'.$view->id);
        $this->removeDirectory($scratch);
        @mkdir($scratch, 0775, true);

        try {
            $modelPath = $scratch.DIRECTORY_SEPARATOR.'model';
            RenderInput::fetch($opened, $modelPath);

            $result = $runner->run(
                [
                    'product_id' => $view->product_id,
                    'source' => [
                        'path' => $opened->path,
                        'format' => $opened === $view->source
                            ? (string) ($view->source->meta['sniffed_format'] ?? $view->source->format)
                            : 'glb',
                        'checksum' => $opened->checksum,
                        'bytes' => (int) $opened->bytes,
                        'faces' => 0,
                    ],
                    'angles' => [$view->camera],
                    'passes' => [$view->pass],
                    'output' => ['width' => 1200, 'height' => 900],
                ],
                $modelPath,
                $scratch
            );
        } catch (Throwable $exception) {
            $log->error('Custom view threw.', ['message' => $exception->getMessage()]);
            $this->giveUp($view, 'That view could not be drawn.');

            return;
        }

        if (($result['status'] ?? 'failed') !== 'ok' || ($result['images'] ?? []) === []) {
            $log->warning('Custom view failed.', ['result' => $result]);
            $this->giveUp($view, $result['reason'] ?? 'That view could not be drawn.');

            return;
        }

        $this->store($view, $scratch, $result['images'][0], $log);
        $this->removeDirectory($scratch);
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

    private function store(CustomView $view, string $scratch, array $image, $log): void
    {
        $name = $image['file'] ?? '';

        // The container parses untrusted geometry, so what it names its output
        // is input: only the one file it was asked for is read.
        if ($name !== $view->pass.'-0.png' && $name !== 'angle-0.png') {
            $log->warning('Renderer named an unexpected file.', ['file' => $name]);
            $this->giveUp($view, 'That view could not be drawn.');

            return;
        }

        $bytes = (string) file_get_contents(
            $scratch.DIRECTORY_SEPARATOR.'out'.DIRECTORY_SEPARATOR.$name
        );
        $path = sprintf(
            'custom_views/%d/%d/%s-%s.png',
            $view->user_id,
            $view->product_file_id,
            substr($view->fingerprint, 0, 16),
            $view->pass
        );

        Storage::disk('public')->put($path, $bytes);

        $view->update([
            'status' => CustomView::READY,
            'disk' => 'public',
            'path' => $path,
            'bytes' => strlen($bytes),
            'failure_reason' => null,
        ]);

        $log->info('Custom view drawn.', ['bytes' => strlen($bytes), 'path' => $path]);
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
