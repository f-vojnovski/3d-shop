<?php

namespace App\Support;

/**
 * The `.mtl` beside an `.obj`, and the images it names.
 *
 * A bare `.obj` upload carries none of this, which is why the measurement
 * reports nothing for it. A bundle does carry it, so the buyer can be told how
 * many materials and what resolution the textures are — the same facts a glTF
 * gives up on its own.
 */
class MaterialLibrary
{
    /** Enough of a cap that a hostile `.mtl` cannot hold the reader open. */
    private const MAX_LINES = 200_000;

    private const IMAGES = ['png', 'jpg', 'jpeg', 'tga', 'bmp', 'tif', 'tiff', 'webp', 'exr', 'hdr'];

    private const MAPS = [
        'map_ka', 'map_kd', 'map_ks', 'map_ns', 'map_d',
        'map_bump', 'bump', 'disp', 'decal', 'refl', 'norm',
    ];

    /**
     * @return array{
     *     materials: int,
     *     textures: list<array{width: int, height: int}>,
     *     missing: list<string>,
     *     unused: list<string>
     * }|null null when the model names no library, or none of them is present
     */
    public static function beside(string $modelPath, string $root): ?array
    {
        $libraries = self::librariesNamedBy($modelPath, $root);

        if ($libraries === []) {
            return null;
        }

        $materials = 0;
        $images = [];
        $missing = [];

        foreach ($libraries as $library) {
            $materials += self::materialsIn($library);
            [$resolved, $unresolved] = self::texturesIn($library, $root);

            foreach ($resolved as $path) {
                $images[$path] = true;
            }

            foreach ($unresolved as $reference) {
                $missing[$reference] = true;
            }
        }

        $textures = [];

        foreach (array_keys($images) as $path) {
            $size = @getimagesize($path);

            // Present but unreadable says nothing useful, so it is left out
            // rather than counted at zero by zero.
            if ($size !== false) {
                $textures[] = ['width' => (int) $size[0], 'height' => (int) $size[1]];
            }
        }

        return [
            'materials' => $materials,
            'textures' => $textures,
            'missing' => array_keys($missing),
            'unused' => self::imagesNotIn($root, array_keys($images)),
        ];
    }

    /**
     * Alone this is housekeeping. Beside a list of references that did not
     * resolve it is the answer: the pack has its textures, under other names.
     *
     * @param  list<string>  $used
     * @return list<string>
     */
    private static function imagesNotIn(string $root, array $used): array
    {
        $base = realpath($root);

        if ($base === false) {
            return [];
        }

        $spare = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            $path = $entry->getPathname();

            if (! in_array(strtolower($entry->getExtension()), self::IMAGES, true)) {
                continue;
            }

            if (! in_array($path, $used, true)) {
                $spare[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($base) + 1));
            }
        }

        sort($spare);

        return $spare;
    }

    /** @return list<string> */
    private static function librariesNamedBy(string $modelPath, string $root): array
    {
        $handle = @fopen($modelPath, 'rb');

        if ($handle === false) {
            return [];
        }

        $found = [];
        $lines = 0;

        while (($line = fgets($handle)) !== false && ++$lines < self::MAX_LINES) {
            if (strncasecmp($line, 'mtllib', 6) !== 0) {
                continue;
            }

            // One `mtllib` may name several files, space separated.
            foreach (preg_split('/\s+/', trim(substr($line, 6))) ?: [] as $name) {
                $resolved = BundlePath::inside($name, dirname($modelPath), $root);

                if ($resolved !== null) {
                    $found[$resolved] = true;
                }
            }
        }

        fclose($handle);

        return array_keys($found);
    }

    private static function materialsIn(string $library): int
    {
        $handle = @fopen($library, 'rb');

        if ($handle === false) {
            return 0;
        }

        $count = 0;
        $lines = 0;

        while (($line = fgets($handle)) !== false && ++$lines < self::MAX_LINES) {
            if (strncasecmp($line, 'newmtl', 6) === 0) {
                $count++;
            }
        }

        fclose($handle);

        return $count;
    }

    /** @return array{0: list<string>, 1: list<string>} resolved paths, then references that did not resolve */
    private static function texturesIn(string $library, string $root): array
    {
        $handle = @fopen($library, 'rb');

        if ($handle === false) {
            return [[], []];
        }

        $found = [];
        $unresolved = [];
        $lines = 0;

        while (($line = fgets($handle)) !== false && ++$lines < self::MAX_LINES) {
            $keyword = strtolower(strtok(trim($line), " \t"));

            if ($keyword === false || ! in_array($keyword, self::MAPS, true)) {
                continue;
            }

            $rest = trim(substr(trim($line), strlen($keyword)));
            $reference = self::filenameIn($rest);
            $resolved = BundlePath::inside($reference, dirname($library), $root);

            if ($resolved !== null) {
                $found[$resolved] = true;
            } elseif ($reference !== '') {
                $unresolved[trim($reference, " 	\"'")] = true;
            }
        }

        fclose($handle);

        return [array_keys($found), array_keys($unresolved)];
    }

    /**
     * A map line may carry options first — `map_Kd -s 1 1 1 body.png`. The last
     * token is the filename in every file we have seen; the whole remainder is
     * tried too, because a name with spaces in it is legal and not rare.
     */
    private static function filenameIn(string $rest): string
    {
        if (! str_starts_with($rest, '-')) {
            return $rest;
        }

        $tokens = preg_split('/\s+/', $rest) ?: [];

        return (string) end($tokens);
    }
}
