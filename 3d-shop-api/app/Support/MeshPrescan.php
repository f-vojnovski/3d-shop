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

    /**
     * What the harness can load. Compressed geometry keeps its counts readable,
     * so a Draco file would otherwise publish full specs and no pictures.
     */
    private const SUPPORTED_EXTENSIONS = [
        'KHR_mesh_quantization',
        'KHR_texture_transform',
    ];

    public function __construct(
        public readonly int $bytes,
        public readonly int $faces,
        public readonly string $format,
        public readonly array $unsupported = [],
    ) {}

    public static function of(string $absolutePath): self
    {
        $bytes = filesize($absolutePath) ?: 0;
        $format = self::sniff($absolutePath);

        return new self(
            bytes: $bytes,
            faces: $format === 'obj' ? self::countObjFaces($absolutePath) : 0,
            format: $format,
            unsupported: $format === 'obj' ? [] : self::unsupportedExtensions($absolutePath, $format),
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
            return 'That file is not a recognised model. We read '
                .self::extensionList().'.';
        }

        if ($this->unsupported !== []) {
            return sprintf(
                'This model needs %s, which our renderer cannot read. Export it without compression.',
                implode(' and ', $this->unsupported)
            );
        }

        return null;
    }

    /** @return list<string> */
    private static function unsupportedExtensions(string $path, string $format): array
    {
        $json = $format === 'glb' ? self::glbJson($path) : @file_get_contents($path);
        $gltf = json_decode((string) $json, true);

        if (! is_array($gltf)) {
            return [];
        }

        return array_values(array_diff(
            array_map('strval', $gltf['extensionsRequired'] ?? []),
            self::SUPPORTED_EXTENSIONS
        ));
    }

    private static function glbJson(string $path): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        $header = (string) fread($handle, 20);

        if (strlen($header) < 20) {
            fclose($handle);

            return '';
        }

        ['length' => $length, 'type' => $type] = unpack('Vlength/Vtype', substr($header, 12, 8));
        $json = $type === 0x4e4f534a ? (string) fread($handle, $length) : '';
        fclose($handle);

        return $json;
    }

    private static function extensionList(): string
    {
        $extensions = [];

        foreach (ModelFormats::all() as $format) {
            foreach (ModelFormats::extensionsFor($format) as $extension) {
                $extensions[] = '.'.$extension;
            }
        }

        $last = array_pop($extensions);

        return implode(', ', $extensions).' or '.$last;
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

        // A bundle: the model and its textures, which is how they actually ship.
        if (str_starts_with($head, "PK")
            || str_starts_with($head, "PK")
            || str_starts_with($head, "PK")) {
            return 'zip';
        }

        if (str_starts_with($head, 'Kaydara FBX Binary') || str_contains($head, 'FBXHeaderExtension')) {
            return 'fbx';
        }

        // Checked before the ascii test: exporters write "solid" into the
        // 80-byte header of binary files too, and only the length agrees.
        if (self::looksLikeBinaryStl($path)) {
            return 'stl';
        }

        if (preg_match('/^\s*solid/', $head) === 1 && str_contains($head, 'facet')) {
            return 'stl';
        }

        if (preg_match('/^\s*(#|v\s|vn\s|vt\s|o\s|g\s|mtllib\s|usemtl\s)/m', $head) === 1) {
            return 'obj';
        }

        return 'unknown';
    }

    private static function looksLikeBinaryStl(string $path): bool
    {
        $size = @filesize($path);

        if ($size === false || $size < 84) {
            return false;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        fseek($handle, 80);
        $header = (string) fread($handle, 4);
        fclose($handle);

        return strlen($header) === 4
            && 84 + ((int) (unpack('V', $header)[1] ?? 0)) * 50 === $size;
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
