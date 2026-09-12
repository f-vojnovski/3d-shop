<?php

namespace App\Jobs;

use App\Models\ProductFile;
use App\Support\ModelConverter;
use App\Support\ProxyRunner;
use App\Support\RenderInput;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the cut-down copy buyers aim a camera at, at the ratio the seller
 * approved on the upload slider.
 *
 * Kept off the render path deliberately: a failed proxy costs a buyer an
 * outline box, while a failed render costs the listing its attested images.
 */
class BuildViewerProxy implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 600;

    public function __construct(public int $sourceFileId) {}

    public function handle(ProxyRunner $runner, ModelConverter $converter): void
    {
        $source = ProductFile::with('product')->find($this->sourceFileId);

        if ($source === null || $source->kind !== ProductFile::KIND_DELIVERABLE || $source->isSuperseded()) {
            return;
        }

        $wanted = $source->meta['proxy'] ?? null;
        $ratio = $wanted['ratio'] ?? null;

        // 'box' is the seller asking for no geometry at all, which is the
        // absence of a proxy rather than a proxy of nothing.
        if (($wanted['mode'] ?? 'box') !== 'model' || ! is_numeric($ratio)) {
            return;
        }

        $log = Log::channel('render');
        $scratch = storage_path('app/private/proxy-scratch/'.$source->id);

        File::deleteDirectory($scratch);
        File::ensureDirectoryExists($scratch, 0775, true);

        try {
            $modelPath = $scratch.DIRECTORY_SEPARATOR.'model';
            RenderInput::fetch($source, $modelPath);

            $scan = RenderInput::scanOf($source);
            $format = $scan->format;
            $bundleDir = null;
            $entry = null;

            if (RenderInput::isBundle($source)) {
                $unpacked = RenderInput::unpack($modelPath, $scratch);

                if (is_string($unpacked)) {
                    $log->warning('Proxy skipped: bundle could not be unpacked.', ['reason' => $unpacked]);

                    return;
                }

                [$bundleDir, $entry] = [$unpacked['dir'], $unpacked['entry']];
            } elseif (ModelConverter::needsConverting($format)) {
                // No browser opens this one, and the page that simplifies it is
                // a browser. The converted copy is what a render draws anyway.
                $converted = $converter->toGlb($modelPath, $scratch);

                if (($converted['status'] ?? 'failed') !== 'ok') {
                    $log->warning('Proxy skipped: conversion failed.', ['result' => $converted]);

                    return;
                }

                $modelPath = $converted['path'];
                $format = 'glb';
            }

            $result = $runner->run(
                (float) $ratio,
                $format,
                $modelPath,
                $scratch,
                $bundleDir,
                $entry
            );

            if (($result['status'] ?? 'failed') !== 'ok') {
                $log->warning('Proxy not built.', ['product_file_id' => $source->id, 'result' => $result]);

                if ($result['retryable'] ?? false) {
                    $this->release(60);
                }

                return;
            }

            $this->store($source, $scratch.DIRECTORY_SEPARATOR.'out'.DIRECTORY_SEPARATOR.$result['file'], $result);

            $log->info('Proxy built.', [
                'product_file_id' => $source->id,
                'triangles' => $result['triangles'] ?? null,
                'bytes' => $result['bytes'] ?? null,
            ]);
        } finally {
            File::deleteDirectory($scratch);
        }
    }

    /** @param  array<string, mixed>  $result */
    private function store(ProductFile $source, string $path, array $result): void
    {
        $source->proxy()?->delete();

        $bytes = (string) file_get_contents($path);
        $checksum = hash('sha256', $bytes);
        $stored = 'proxies/'.$source->product_id.'-'.$source->format.'-'.substr($checksum, 0, 12).'.glb';

        // The private disk, served through the same gate as an interactive
        // preview: this is geometry, and a public URL would be a second way out.
        Storage::disk($source->disk)->put($stored, $bytes);

        $source->product->files()->create([
            'source_file_id' => $source->id,
            'kind' => ProductFile::KIND_PROXY,
            'format' => 'glb',
            'disk' => $source->disk,
            'path' => $stored,
            'sort' => 0,
            'bytes' => strlen($bytes),
            'checksum' => $checksum,
            'meta' => [
                'ratio' => $source->meta['proxy']['ratio'] ?? null,
                'triangles' => $result['triangles'] ?? null,
            ],
        ]);
    }
}
