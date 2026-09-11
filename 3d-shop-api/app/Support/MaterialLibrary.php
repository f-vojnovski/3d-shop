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

    private const MAPS = [
        'map_ka', 'map_kd', 'map_ks', 'map_ns', 'map_d',
        'map_bump', 'bump', 'disp', 'decal', 'refl', 'norm',
    ];

    /**
     * @return array{materials: int, textures: list<array{width: int, height: int}>}|null
     *                                                                             null when the model names no library, or none of them is present
     */
    public static function beside(string $modelPath, string $root): ?array
    {
        $libraries = self::librariesNamedBy($modelPath, $root);

        if ($libraries === []) {
            return null;
        }

        $materials = 0;
        $images = [];

        foreach ($libraries as $library) {
            $materials += self::materialsIn($library);

            foreach (self::texturesIn($library, $root) as $path) {
                $images[$path] = true;
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

        return ['materials' => $materials, 'textures' => $textures];
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
                $resolved = self::resolve($name, dirname($modelPath), $root);

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

    /** @return list<string> */
    private static function texturesIn(string $library, string $root): array
    {
        $handle = @fopen($library, 'rb');

        if ($handle === false) {
            return [];
        }

        $found = [];
        $lines = 0;

        while (($line = fgets($handle)) !== false && ++$lines < self::MAX_LINES) {
            $keyword = strtolower(strtok(trim($line), " \t"));

            if ($keyword === false || ! in_array($keyword, self::MAPS, true)) {
                continue;
            }

            $rest = trim(substr(trim($line), strlen($keyword)));
            $resolved = self::resolve(self::filenameIn($rest), dirname($library), $root);

            if ($resolved !== null) {
                $found[$resolved] = true;
            }
        }

        fclose($handle);

        return array_keys($found);
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

    /** Inside the bundle or nowhere: an absolute path names the artist's disk. */
    private static function resolve(string $name, string $from, string $root): ?string
    {
        $name = trim(str_replace('\\', '/', $name), " \t\"'");

        if ($name === '' || preg_match('#^([a-zA-Z]:|/|\\\\)#', $name) === 1) {
            return null;
        }

        $path = realpath($from.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $name));
        $base = realpath($root);

        if ($path === false || $base === false || ! is_file($path)) {
            return null;
        }

        return $path === $base || str_starts_with($path, $base.DIRECTORY_SEPARATOR) ? $path : null;
    }
}
