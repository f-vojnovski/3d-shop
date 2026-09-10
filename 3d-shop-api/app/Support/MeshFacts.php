<?php

namespace App\Support;

/**
 * The measurements a buyer of a 3D model asks for, taken from the file itself
 * rather than typed in by the seller. Runs once at upload, while the file is
 * still on local disk, after MeshPrescan has admitted it.
 *
 * Nothing here decodes an image or builds a scene: counts come from a single
 * streaming pass for .obj, and from the glTF header plus byte ranges read on
 * demand, so a 600 MB model costs the same memory as a small one.
 */
class MeshFacts
{
    public const TRIANGLES = 'triangles';

    public const QUADS = 'quads';

    public const MIXED = 'mixed';

    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly ?int $vertices,
        public readonly ?int $faces,
        public readonly string $topology,
        public readonly bool $normals,
        public readonly bool $uvs,
        public readonly ?int $materials,
        /** @var list<array{width: int, height: int}> */
        public readonly array $textures,
        /** @var array{min: list<float>, max: list<float>, size: list<float>}|null */
        public readonly ?array $bounds,
        public readonly bool $rigged,
        public readonly bool $animated,
    ) {}

    public static function of(string $absolutePath, string $format): self
    {
        return match ($format) {
            'obj' => self::fromObj($absolutePath),
            'gltf', 'glb' => self::fromGltf($absolutePath, $format === 'glb'),
            default => self::nothing(),
        };
    }

    public function toArray(): array
    {
        return [
            'vertices' => $this->vertices,
            'faces' => $this->faces,
            'topology' => $this->topology,
            'normals' => $this->normals,
            'uvs' => $this->uvs,
            'materials' => $this->materials,
            'textures' => $this->textures,
            'bounds' => $this->bounds,
            'rigged' => $this->rigged,
            'animated' => $this->animated,
        ];
    }

    private static function nothing(): self
    {
        return new self(null, null, self::UNKNOWN, false, false, null, [], null, false, false);
    }

    // ---------------------------------------------------------------- .obj

    private static function fromObj(string $path): self
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return self::nothing();
        }

        $vertices = 0;
        $faces = 0;
        $normals = false;
        $uvs = false;
        $min = [INF, INF, INF];
        $max = [-INF, -INF, -INF];
        $corners = [];

        while (($line = fgets($handle)) !== false) {
            // Cheapest possible discrimination: almost every line is v or f.
            $kind = substr($line, 0, 2);

            if ($kind === 'v ') {
                $vertices++;
                $point = sscanf($line, 'v %f %f %f');

                for ($axis = 0; $axis < 3; $axis++) {
                    $value = $point[$axis] ?? null;

                    if ($value === null) {
                        continue;
                    }

                    $min[$axis] = min($min[$axis], $value);
                    $max[$axis] = max($max[$axis], $value);
                }

                continue;
            }

            if ($kind === 'f ') {
                $faces++;
                $corners[substr_count(trim($line), ' ')] = true;

                // Declaring `vt` and `vn` blocks proves nothing: a face has to
                // reference them, and `f 1 2 3` references neither however many
                // texture coordinates sit above it in the file.
                if (! $uvs || ! $normals) {
                    $corner = strtok(substr($line, 2), " 	
");
                    $slots = $corner === false ? [] : explode('/', $corner);
                    $uvs = $uvs || (($slots[1] ?? '') !== '');
                    $normals = $normals || (($slots[2] ?? '') !== '');
                }

                continue;
            }


        }

        fclose($handle);

        return new self(
            vertices: $vertices,
            faces: $faces,
            topology: self::topologyOf(array_keys($corners)),
            normals: $normals,
            uvs: $uvs,
            // Left unreported rather than guessed: an .obj keeps its materials
            // in a .mtl, which a single-file upload never carries, so any
            // number here would describe something the buyer does not receive.
            materials: null,
            textures: [],
            bounds: self::boundsOf($min, $max),
            rigged: false,
            animated: false,
        );
    }

    /** @param list<int> $cornerCounts */
    private static function topologyOf(array $cornerCounts): string
    {
        if ($cornerCounts === []) {
            return self::UNKNOWN;
        }

        if ($cornerCounts === [3]) {
            return self::TRIANGLES;
        }

        if ($cornerCounts === [4]) {
            return self::QUADS;
        }

        return self::MIXED;
    }

    // --------------------------------------------------------------- glTF

    private static function fromGltf(string $path, bool $binary): self
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return self::nothing();
        }

        [$gltf, $binOffset, $binLength] = $binary
            ? self::readGlbChunks($handle)
            : [json_decode((string) stream_get_contents($handle), true), null, 0];

        if (! is_array($gltf)) {
            fclose($handle);

            return self::nothing();
        }

        $accessors = $gltf['accessors'] ?? [];
        $meshes = $gltf['meshes'] ?? [];
        $vertices = 0;
        $faces = 0;
        $normals = false;
        $uvs = false;
        $modes = [];
        $min = [INF, INF, INF];
        $max = [-INF, -INF, -INF];

        // Walking the scene rather than the mesh list: a mesh reused by several
        // nodes is several objects on screen, and its transform is what puts
        // the bounding box in the units a buyer cares about.
        foreach (self::sceneInstances($gltf) as [$meshIndex, $world]) {
            foreach ($meshes[$meshIndex]['primitives'] ?? [] as $primitive) {
                $attributes = $primitive['attributes'] ?? [];
                $position = $attributes['POSITION'] ?? null;

                if ($position === null) {
                    continue;
                }

                $count = (int) ($accessors[$position]['count'] ?? 0);
                $vertices += $count;
                $modes[$primitive['mode'] ?? 4] = true;

                $indices = $primitive['indices'] ?? null;
                $faces += intdiv(
                    $indices === null ? $count : (int) ($accessors[$indices]['count'] ?? 0),
                    3
                );

                $normals = $normals || isset($attributes['NORMAL']);
                $uvs = $uvs || isset($attributes['TEXCOORD_0']);

                self::growBounds(
                    $min,
                    $max,
                    $accessors[$position] ?? [],
                    $world,
                    $gltf['bufferViews'] ?? [],
                    $binOffset === null ? null : $handle,
                    $binOffset ?? 0
                );
            }
        }

        $textures = $binOffset === null
            ? []
            : self::textureSizes($handle, $gltf, $binOffset, $binLength);

        fclose($handle);

        return new self(
            vertices: $vertices,
            faces: $faces,
            // glTF has no quads; the modes tell us whether it is all triangles.
            topology: $modes === [4 => true] ? self::TRIANGLES : ($modes === [] ? self::UNKNOWN : self::MIXED),
            normals: $normals,
            uvs: $uvs,
            materials: count($gltf['materials'] ?? []),
            textures: $textures,
            bounds: self::boundsOf($min, $max),
            rigged: ($gltf['skins'] ?? []) !== [],
            animated: ($gltf['animations'] ?? []) !== [],
        );
    }

    /**
     * @param  resource  $handle
     * @return array{0: mixed, 1: int|null, 2: int}
     */
    private static function readGlbChunks($handle): array
    {
        $header = (string) fread($handle, 12);

        if (strlen($header) < 12 || substr($header, 0, 4) !== 'glTF') {
            return [null, null, 0];
        }

        $gltf = null;
        $binOffset = null;
        $binLength = 0;
        $at = 12;

        while (! feof($handle)) {
            fseek($handle, $at);
            $chunkHeader = (string) fread($handle, 8);

            if (strlen($chunkHeader) < 8) {
                break;
            }

            ['length' => $length, 'type' => $type] = unpack('Vlength/Vtype', $chunkHeader);

            if ($type === 0x4e4f534a) {
                $gltf = json_decode((string) fread($handle, $length), true);
            } elseif ($type === 0x004e4942) {
                $binOffset = $at + 8;
                $binLength = $length;
            }

            $at += 8 + $length + ((4 - ($length % 4)) % 4);
        }

        return [$gltf, $binOffset, $binLength];
    }

    /**
     * Every mesh in the scene with the world matrix it is drawn under.
     *
     * @return list<array{0: int, 1: list<float>}>
     */
    private static function sceneInstances(array $gltf): array
    {
        $nodes = $gltf['nodes'] ?? [];
        $scene = $gltf['scenes'][$gltf['scene'] ?? 0]['nodes'] ?? [];
        $found = [];

        $walk = function (int $index, array $parent) use (&$walk, $nodes, &$found): void {
            $node = $nodes[$index] ?? [];
            $world = self::multiply($parent, self::localMatrix($node));

            if (isset($node['mesh'])) {
                $found[] = [(int) $node['mesh'], $world];
            }

            foreach ($node['children'] ?? [] as $child) {
                $walk((int) $child, $world);
            }
        };

        foreach ($scene as $root) {
            $walk((int) $root, self::identity());
        }

        return $found;
    }

    /** @return list<float> */
    private static function identity(): array
    {
        return [1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1];
    }

    /** @return list<float> */
    private static function localMatrix(array $node): array
    {
        if (isset($node['matrix'])) {
            return array_map('floatval', $node['matrix']);
        }

        [$x, $y, $z, $w] = $node['rotation'] ?? [0, 0, 0, 1];
        [$sx, $sy, $sz] = $node['scale'] ?? [1, 1, 1];
        [$tx, $ty, $tz] = $node['translation'] ?? [0, 0, 0];

        return [
            (1 - 2 * ($y * $y + $z * $z)) * $sx, (2 * ($x * $y + $z * $w)) * $sx, (2 * ($x * $z - $y * $w)) * $sx, 0,
            (2 * ($x * $y - $z * $w)) * $sy, (1 - 2 * ($x * $x + $z * $z)) * $sy, (2 * ($y * $z + $x * $w)) * $sy, 0,
            (2 * ($x * $z + $y * $w)) * $sz, (2 * ($y * $z - $x * $w)) * $sz, (1 - 2 * ($x * $x + $y * $y)) * $sz, 0,
            $tx, $ty, $tz, 1,
        ];
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     * @return list<float>
     */
    private static function multiply(array $a, array $b): array
    {
        $out = array_fill(0, 16, 0.0);

        for ($row = 0; $row < 4; $row++) {
            for ($column = 0; $column < 4; $column++) {
                for ($k = 0; $k < 4; $k++) {
                    $out[$column * 4 + $row] += $a[$k * 4 + $row] * $b[$column * 4 + $k];
                }
            }
        }

        return $out;
    }

    /**
     * Exact where the vertex data is reachable, and an over-estimate where it
     * is not: rotating the corners of an axis-aligned box gives a box that
     * contains the rotated geometry rather than hugging it, which on a car
     * measured 17cm too wide. Real positions cost a sequential read and no
     * extra memory, so they are worth it for a number shown as measured.
     *
     * @param  list<float>  $min
     * @param  list<float>  $max
     * @param  list<float>  $world
     * @param  resource|null  $handle
     */
    private static function growBounds(
        array &$min,
        array &$max,
        array $accessor,
        array $world,
        array $views,
        $handle,
        int $binOffset
    ): void {
        if ($handle !== null && self::growFromPositions($min, $max, $accessor, $world, $views, $handle, $binOffset)) {
            return;
        }

        $low = $accessor['min'] ?? null;
        $high = $accessor['max'] ?? null;

        if (! is_array($low) || ! is_array($high) || count($low) < 3 || count($high) < 3) {
            return;
        }

        for ($corner = 0; $corner < 8; $corner++) {
            self::grow($min, $max, $world, [
                $corner & 1 ? (float) $high[0] : (float) $low[0],
                $corner & 2 ? (float) $high[1] : (float) $low[1],
                $corner & 4 ? (float) $high[2] : (float) $low[2],
            ]);
        }
    }

    /**
     * @param  list<float>  $min
     * @param  list<float>  $max
     * @param  list<float>  $world
     * @param  resource  $handle
     */
    private static function growFromPositions(
        array &$min,
        array &$max,
        array $accessor,
        array $world,
        array $views,
        $handle,
        int $binOffset
    ): bool {
        $view = $views[$accessor['bufferView'] ?? -1] ?? null;
        $count = (int) ($accessor['count'] ?? 0);

        // Only the ordinary case: tightly packed float32 triples in buffer 0.
        if (
            $view === null
            || ($view['buffer'] ?? 0) !== 0
            || ($accessor['componentType'] ?? null) !== 5126
            || ($accessor['type'] ?? null) !== 'VEC3'
            || isset($accessor['sparse'])
            || $count === 0
        ) {
            return false;
        }

        $stride = (int) ($view['byteStride'] ?? 12);

        if ($stride !== 12) {
            return false;
        }

        $at = $binOffset + (int) ($view['byteOffset'] ?? 0) + (int) ($accessor['byteOffset'] ?? 0);

        if (fseek($handle, $at) !== 0) {
            return false;
        }

        $remaining = $count;

        while ($remaining > 0) {
            $batch = min($remaining, 4096);
            $bytes = (string) fread($handle, $batch * 12);

            if (strlen($bytes) < $batch * 12) {
                return false;
            }

            $floats = unpack('g'.($batch * 3), $bytes);

            for ($i = 0; $i < $batch; $i++) {
                self::grow($min, $max, $world, [
                    $floats[$i * 3 + 1],
                    $floats[$i * 3 + 2],
                    $floats[$i * 3 + 3],
                ]);
            }

            $remaining -= $batch;
        }

        return true;
    }

    /**
     * @param  list<float>  $min
     * @param  list<float>  $max
     * @param  list<float>  $world
     * @param  list<float>  $point
     */
    private static function grow(array &$min, array &$max, array $world, array $point): void
    {
        for ($axis = 0; $axis < 3; $axis++) {
            $value = $world[$axis] * $point[0]
                + $world[4 + $axis] * $point[1]
                + $world[8 + $axis] * $point[2]
                + $world[12 + $axis];

            if ($value < $min[$axis]) {
                $min[$axis] = $value;
            }

            if ($value > $max[$axis]) {
                $max[$axis] = $value;
            }
        }
    }

    /**
     * @param  list<float>  $min
     * @param  list<float>  $max
     * @return array{min: list<float>, max: list<float>, size: list<float>}|null
     */
    private static function boundsOf(array $min, array $max): ?array
    {
        if (! is_finite($min[0]) || ! is_finite($max[0])) {
            return null;
        }

        $round = fn (float $value): float => round($value, 4);

        return [
            'min' => array_map($round, $min),
            'max' => array_map($round, $max),
            'size' => array_map($round, [$max[0] - $min[0], $max[1] - $min[1], $max[2] - $min[2]]),
        ];
    }

    /**
     * Image dimensions from their headers alone — no decoding, and only the
     * first few bytes of each texture are ever read.
     *
     * @param  resource  $handle
     * @return list<array{width: int, height: int}>
     */
    private static function textureSizes($handle, array $gltf, int $binOffset, int $binLength): array
    {
        $views = $gltf['bufferViews'] ?? [];
        $sizes = [];

        foreach ($gltf['images'] ?? [] as $image) {
            $view = $views[$image['bufferView'] ?? -1] ?? null;

            if ($view === null || ($view['buffer'] ?? 0) !== 0) {
                continue;
            }

            $offset = $binOffset + (int) ($view['byteOffset'] ?? 0);

            if ($offset + 32 > $binOffset + $binLength) {
                continue;
            }

            fseek($handle, $offset);
            $size = self::imageSize((string) fread($handle, 32));

            if ($size !== null) {
                $sizes[] = $size;
            }
        }

        return $sizes;
    }

    /** @return array{width: int, height: int}|null */
    private static function imageSize(string $head): ?array
    {
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            $ihdr = unpack('Nwidth/Nheight', substr($head, 16, 8));

            return ['width' => $ihdr['width'], 'height' => $ihdr['height']];
        }

        if (str_starts_with($head, "\xff\xd8")) {
            return self::jpegSize($head);
        }

        return null;
    }

    /**
     * JPEG keeps its dimensions in a start-of-frame marker whose position
     * depends on the segments before it, so this walks the segment chain.
     *
     * @return array{width: int, height: int}|null
     */
    private static function jpegSize(string $head): ?array
    {
        $at = 2;
        $length = strlen($head);

        while ($at + 9 < $length) {
            if ($head[$at] !== "\xff") {
                return null;
            }

            $marker = ord($head[$at + 1]);
            $segment = unpack('n', substr($head, $at + 2, 2))[1] ?? 0;

            if ($marker >= 0xc0 && $marker <= 0xcf && ! in_array($marker, [0xc4, 0xc8, 0xcc], true)) {
                $frame = unpack('nheight/nwidth', substr($head, $at + 5, 4));

                return ['width' => $frame['width'], 'height' => $frame['height']];
            }

            $at += 2 + $segment;
        }

        return null;
    }
}
