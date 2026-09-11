<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductFile;
use Illuminate\Support\Facades\Storage;

/**
 * Shared by the render job and `render:verify`. If the two built the request
 * differently, a verification failure would prove nothing about the renderer.
 */
class RenderInput
{
    public static function scanOf(ProductFile $source): MeshPrescan
    {
        // Recorded at upload, so rejecting costs no transfer from object storage.
        return new MeshPrescan(
            bytes: (int) $source->bytes,
            faces: (int) ($source->meta['faces'] ?? $source->meta['triangles'] ?? 0),
            format: (string) ($source->meta['sniffed_format'] ?? 'unknown'),
        );
    }

    public static function request(
        Product $product,
        ProductFile $source,
        MeshPrescan $scan,
        ?string $rendered = null
    ): array {
        return [
            'product_id' => $product->id,
            'source' => [
                'path' => $source->path,
                'format' => $rendered ?? $scan->format,
                'checksum' => $source->checksum,
                'bytes' => $scan->bytes,
                'faces' => $scan->faces,
            ],
            'angles' => $source->angles(),
            'output' => ['width' => 1200, 'height' => 900],
        ];
    }

    public static function fetch(ProductFile $source, string $target): int
    {
        $read = Storage::disk($source->disk)->readStream($source->path);
        $write = fopen($target, 'wb');
        stream_copy_to_stream($read, $write);
        fclose($write);
        fclose($read);

        return (int) filesize($target);
    }
}
