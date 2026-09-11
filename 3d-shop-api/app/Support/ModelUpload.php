<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * One uploaded model, whether it arrived as a bare file or as an archive of a
 * model with its textures. Everything downstream asks this what it is holding
 * rather than sniffing the file again.
 */
class ModelUpload
{
    public function __construct(
        public readonly MeshPrescan $scan,
        public readonly MeshFacts $facts,
        /** Relative path of the model inside the archive; null for a bare file. */
        public readonly ?string $entry = null,
        /** @var list<array{path: string, sha256: string, bytes: int}> */
        public readonly array $manifest = [],
        public readonly ?string $digest = null,
        /**
         * Textures a material named that the archive does not hold, and images
         * it does hold that nothing names.
         *
         * @var array{missing: list<string>, unused: list<string>}|null
         */
        public readonly ?array $textureReport = null,
        public readonly ?string $refusal = null,
    ) {}

    public static function read(string $path, string $format): self
    {
        $scan = MeshPrescan::of($path);

        return $scan->format === 'zip'
            ? self::fromBundle($path, $format)
            : self::fromFile($path, $scan, $format);
    }

    public function isBundle(): bool
    {
        return $this->entry !== null;
    }

    public function accepted(): bool
    {
        return $this->refusal === null;
    }

    private static function fromFile(string $path, MeshPrescan $scan, string $format): self
    {
        if ($refusal = $scan->rejection()) {
            return self::refuse($scan, $refusal);
        }

        // The extension is the uploader's word; the magic bytes are not.
        if (! in_array($scan->format, ModelFormats::extensionsFor($format), true)) {
            return self::refuse($scan, sprintf('That file is a .%s, not a .%s.', $scan->format, $format));
        }

        return new self($scan, MeshFacts::of($path, $scan->format));
    }

    /**
     * A bundle is measured from the model inside it. The archive stays as it
     * was uploaded — it is what the buyer downloads — so the unpacked copy
     * exists only long enough to be read.
     */
    private static function fromBundle(string $path, string $format): self
    {
        $inspection = BundleInspector::of($path);

        if (! $inspection->allowed()) {
            return self::refuse(MeshPrescan::of($path), (string) $inspection->refusal);
        }

        $scratch = storage_path('app/private/bundle-read/'.bin2hex(random_bytes(8)));

        try {
            $unpacked = BundleExtractor::extract($path, $inspection, $scratch);

            if (! $unpacked->succeeded()) {
                return self::refuse(MeshPrescan::of($path), (string) $unpacked->failure);
            }

            $entry = self::modelIn($unpacked, $format);

            if (is_string($entry) === false) {
                return self::refuse(MeshPrescan::of($path), $entry[0]);
            }

            $inner = $scratch.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $entry);
            $scan = MeshPrescan::of($inner);

            if ($refusal = $scan->rejection()) {
                return self::refuse($scan, $refusal);
            }

            $facts = MeshFacts::of($inner, $scan->format, $scratch);
            $library = MaterialLibrary::beside($inner, $scratch);

            return new self(
                scan: $scan,
                // A bundle carries the .mtl a bare .obj cannot, so the material
                // and texture counts stop being unmeasurable for it.
                facts: $library === null
                    ? $facts
                    : $facts->withMaterials($library['materials'], $library['textures']),
                entry: $entry,
                manifest: $unpacked->manifest,
                digest: $unpacked->digest(),
                textureReport: $library === null ? null : [
                    'missing' => $library['missing'],
                    'unused' => $library['unused'],
                ],
            );
        } finally {
            File::deleteDirectory($scratch);
        }
    }

    /** @return string|array{0: string} the entry, or a reason there is not one */
    private static function modelIn(BundleExtractor $unpacked, string $format): string|array
    {
        $wanted = ModelFormats::extensionsFor($format);

        $matching = array_values(array_filter(
            $unpacked->models(),
            fn (string $file) => in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $wanted, true)
        ));

        if ($matching === []) {
            $found = $unpacked->models();

            if ($found === []) {
                // A very common shape: the download is a wrapper holding the
                // textures loose and the real bundle one level in. We do not
                // open it, so say which one to upload instead.
                $inner = array_values(array_filter(
                    $unpacked->files,
                    fn (string $f) => strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'zip'
                ));

                return [$inner === []
                    ? 'That archive holds no model file.'
                    : sprintf(
                        'That archive holds no model file. The model looks to be inside %s — upload that instead.',
                        implode(' or ', $inner)
                    )];
            }

            return [sprintf(
                'That archive holds no .%s file. It holds %s.',
                $format,
                implode(', ', array_map(fn (string $f) => basename($f), $found))
            )];
        }

        if (count($matching) > 1) {
            return [sprintf(
                'That archive holds more than one .%s file: %s. Upload one model per format.',
                $format,
                implode(', ', array_map(fn (string $f) => basename($f), $matching))
            )];
        }

        return $matching[0];
    }

    private static function refuse(MeshPrescan $scan, string $why): self
    {
        return new self($scan, MeshFacts::of('', 'unknown'), refusal: $why);
    }
}
