<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Shared by the render job and `render:verify`. If the two built the request
 * differently, a verification failure would prove nothing about the renderer.
 */
class RenderInput
{
    /**
     * @return array{dir: string, entry: string, files: int, bytes: int}|string
     *                                                                          the unpacked bundle, or why it could not be opened
     */
    /**
     * @param  string|null  $format  the format the listing claims, so the file
     *                               that gets drawn is the one that was
     *                               measured. Null only where nothing has been
     *                               claimed yet, such as a bare conversion.
     */
    public static function unpack(string $archivePath, string $scratch, ?string $format = null): array|string
    {
        $inspection = BundleInspector::of($archivePath);

        if (! $inspection->allowed()) {
            return (string) $inspection->refusal;
        }

        $directory = $scratch.DIRECTORY_SEPARATOR.'bundle';
        $unpacked = BundleExtractor::extract($archivePath, $inspection, $directory);

        if (! $unpacked->succeeded()) {
            return (string) $unpacked->failure;
        }

        $models = $format === null ? $unpacked->models() : $unpacked->modelsFor($format);

        if ($models === []) {
            return $format === null
                ? 'That archive holds no model file.'
                : sprintf('That archive holds no .%s file to draw.', $format);
        }

        return [
            'dir' => $directory,
            'entry' => $models[0],
            'files' => count($unpacked->files),
            'bytes' => $unpacked->bytes,
        ];
    }

    public static function isBundle(ProductFile $source): bool
    {
        return ($source->meta['bundle'] ?? null) !== null;
    }

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
        ?string $rendered = null,
        ?string $entry = null
    ): array {
        return [
            'product_id' => $product->id,
            'source' => array_filter([
                // Names the model inside a bundle; absent for a bare file.
                'entry' => $entry,
                'path' => $source->path,
                'format' => $rendered ?? $scan->format,
                'checksum' => $source->checksum,
                'bytes' => $scan->bytes,
                'faces' => $scan->faces,
            ], fn ($value) => $value !== null),
            'angles' => $source->angles(),
            'output' => ['width' => 1200, 'height' => 900],
        ];
    }

    public static function fetch(ProductFile $source, string $target): int
    {
        $read = Storage::disk($source->disk)->readStream($source->path);

        // The model disks are configured not to throw, so a missing object arrives as null.
        if (! is_resource($read)) {
            throw new RuntimeException("Nothing to read at {$source->path} on the {$source->disk} disk.");
        }

        $write = fopen($target, 'wb');
        stream_copy_to_stream($read, $write);
        fclose($write);
        fclose($read);

        return (int) filesize($target);
    }
}
