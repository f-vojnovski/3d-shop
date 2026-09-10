<?php

namespace App\Support;

/**
 * Counts geometry without parsing it. Measured at 0.13s for a 106 MB OBJ and
 * 2.7s for 929 MB, against 4.8s and 72s to parse the same files, so this runs
 * before a render worker is committed to anything.
 */
class MeshPrescan
{
    public const MAX_FACES = 5_000_000;

    // Measured: the renderer needs about 2.8x the file size in memory against
    // a 2 GB container cap.
    public const MAX_BYTES = 600 * 1024 * 1024;

    private const CHUNK = 1 << 22;

    public function __construct(
        public readonly int $bytes,
        public readonly int $faces,
        public readonly string $format,
    ) {}

    public static function of(string $absolutePath): self
    {
        $bytes = filesize($absolutePath) ?: 0;
        $format = self::sniff($absolutePath);

        return new self(
            bytes: $bytes,
            faces: $format === 'obj' ? self::countObjFaces($absolutePath) : 0,
            format: $format,
        );
    }

    public function withinLimits(): bool
    {
        return $this->faces <= self::MAX_FACES && $this->bytes <= self::MAX_BYTES;
    }

    public function rejection(): ?string
    {
        if ($this->faces > self::MAX_FACES) {
            return sprintf(
                'This model has about %s faces, over the %s limit for preview rendering.',
                number_format($this->faces),
                number_format(self::MAX_FACES)
            );
        }

        // The only bound glTF gets: counting its faces would mean parsing it.
        if ($this->bytes > self::MAX_BYTES) {
            return sprintf(
                'This model is %s MB, over the %s MB limit for preview rendering.',
                number_format($this->bytes / 1048576, 1),
                number_format(self::MAX_BYTES / 1048576)
            );
        }

        if ($this->format === 'unknown') {
            return 'That file is not a recognised .obj, .gltf or .glb model.';
        }

        return null;
    }

    /** Magic bytes, not the filename, which the uploader controls. */
    private static function sniff(string $path): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return 'unknown';
        }

        $head = (string) fread($handle, 512);
        fclose($handle);

        if (str_starts_with($head, 'glTF')) {
            return 'glb';
        }

        if (preg_match('/^\s*[{\[]/', $head) === 1 && str_contains($head, '"asset"')) {
            return 'gltf';
        }

        if (preg_match('/^\s*(#|v\s|vn\s|vt\s|o\s|g\s|mtllib\s|usemtl\s)/m', $head) === 1) {
            return 'obj';
        }

        return 'unknown';
    }

    private static function countObjFaces(string $path): int
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return 0;
        }

        $faces = 0;
        $carry = '';

        while (($chunk = fread($handle, self::CHUNK)) !== false && $chunk !== '') {
            $buffer = $carry.$chunk;
            $faces += substr_count($buffer, "\nf ");
            // A face line could straddle the chunk boundary.
            $carry = substr($buffer, -2);
        }

        fclose($handle);

        return $faces;
    }
}
