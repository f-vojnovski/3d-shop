<?php

namespace App\Jobs;

use App\Models\ProductFile;
use App\Support\AnimateRunner;
use App\Support\ModelConverter;
use App\Support\RenderInput;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Draws one of a model's animation clips as moving pictures, one per pass.
 *
 * Kept off the render path deliberately: a failed clip costs a listing a moving
 * preview, while a failed render costs it its attested images.
 */
class RenderClipPreview implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    /**
     * @param  array{position: list<float>, target?: list<float>, up?: list<float>, fov?: float}  $camera
     * @param  list<string>  $passes
     */
    public function __construct(
        public int $sourceFileId,
        public int $clip = 0,
        public array $camera = ['position' => [3.6, 1.35, 6.6], 'target' => [0, 0, 0], 'fov' => 40],
        public array $passes = AnimateRunner::PASSES,
        public int $frames = 24,
        public int $size = 512,
    ) {}

    public function handle(AnimateRunner $runner, ModelConverter $converter): void
    {
        $source = ProductFile::with('product')->find($this->sourceFileId);

        if ($source === null || $source->kind !== ProductFile::KIND_DELIVERABLE || $source->isSuperseded()) {
            return;
        }

        $log = Log::channel('render');
        $scratch = storage_path('app/private/clip-scratch/'.$source->id.'-'.$this->clip);

        File::deleteDirectory($scratch);
        File::ensureDirectoryExists($scratch, 0775, true);

        try {
            $modelPath = $scratch.DIRECTORY_SEPARATOR.'model';
            RenderInput::fetch($source, $modelPath);

            $format = RenderInput::scanOf($source)->format;
            $bundleDir = null;
            $entry = null;

            if (RenderInput::isBundle($source)) {
                $unpacked = RenderInput::unpack($modelPath, $scratch);

                if (is_string($unpacked)) {
                    $log->warning('Clip skipped: bundle could not be unpacked.', ['reason' => $unpacked]);

                    return;
                }

                [$bundleDir, $entry] = [$unpacked['dir'], $unpacked['entry']];
            } elseif (ModelConverter::needsConverting($format)) {
                // No browser opens this one, and the page that draws a clip is
                // a browser. The converted copy is what a render draws anyway.
                $converted = $converter->toGlb($modelPath, $scratch);

                if (($converted['status'] ?? 'failed') !== 'ok') {
                    $log->warning('Clip skipped: conversion failed.', ['result' => $converted]);

                    return;
                }

                $modelPath = $converted['path'];
                $format = 'glb';
            }

            $result = $runner->run(
                $this->clip,
                $this->frames,
                $this->size,
                $this->camera,
                $this->passes,
                $format,
                $modelPath,
                $scratch,
                $bundleDir,
                $entry
            );

            if (($result['status'] ?? 'failed') !== 'ok') {
                $log->warning('Clip not drawn.', ['product_file_id' => $source->id, 'result' => $result]);

                if ($result['retryable'] ?? false) {
                    $this->release(60);
                }

                return;
            }

            $this->store($source, $scratch.DIRECTORY_SEPARATOR.'out', $result);

            $log->info('Clip drawn.', [
                'product_file_id' => $source->id,
                'clip' => $result['clip'] ?? null,
                'passes' => count($result['files'] ?? []),
                'seconds' => $result['seconds'] ?? null,
            ]);
        } finally {
            File::deleteDirectory($scratch);
        }
    }

    /**
     * Replaces this clip's pictures, leaving the model's other clips alone: a
     * seller redrawing the walk has not withdrawn the run.
     *
     * @param  array<string, mixed>  $result
     */
    private function store(ProductFile $source, string $outDir, array $result): void
    {
        $source->clips()
            ->where('meta->clip->index', $this->clip)
            ->each(fn (ProductFile $old) => $old->delete());

        foreach ($result['files'] ?? [] as $sort => $one) {
            $bytes = (string) file_get_contents($outDir.DIRECTORY_SEPARATOR.$one['file']);
            $checksum = hash('sha256', $bytes);
            $stored = 'clips/'.$source->product_id.'-'.$this->clip.'-'.$one['pass']
                .'-'.substr($checksum, 0, 12).'.webp';

            // Pixels, so the public disk, same as a preview image. Nothing here
            // can be turned back into geometry or motion.
            Storage::disk('public')->put($stored, $bytes);

            $source->product->files()->create([
                'source_file_id' => $source->id,
                'kind' => ProductFile::KIND_CLIP,
                'format' => 'webp',
                'disk' => 'public',
                'path' => $stored,
                'sort' => $sort,
                'bytes' => strlen($bytes),
                'checksum' => $checksum,
                'meta' => [
                    'pass' => $one['pass'],
                    'clip' => $result['clip'] ?? null,
                    'clips' => $result['clips'] ?? [],
                    'frames' => $result['frames'] ?? null,
                    'frame_ms' => $result['frameMs'] ?? null,
                    'width' => $result['width'] ?? null,
                    'height' => $result['height'] ?? null,
                    'camera' => $this->camera,
                ],
            ]);
        }
    }
}
