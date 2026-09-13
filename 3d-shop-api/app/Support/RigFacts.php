<?php

namespace App\Support;

/**
 * What a buyer of a rigged model wants to know and cannot check for themselves:
 * how many bones there really are, how many of them do any work, whether any of
 * the mesh is attached to nothing, and what each animation is called.
 *
 * Read straight from the glTF header, plus one streamed pass over the bone
 * weights: no scene is built and nothing is held whole.
 */
class RigFacts
{
    /** Weights are stored as floats or as whole numbers standing in for 0..1. */
    private const SCALES = [5126 => 1.0, 5121 => 255.0, 5123 => 65535.0];

    private const SIZES = [5121 => 1, 5123 => 2, 5125 => 4, 5126 => 4];

    /**
     * Distinctive bone names, and what they say about where the rig came from.
     *
     * Only markers that belong to one family are listed; a plain `Hips` is
     * shared by too many to name anything.
     *
     * @var array<string, list<string>>
     */
    private const FAMILIES = [
        'Mixamo' => ['mixamorig'],
        '3ds Max Biped' => ['bip01', 'bip001'],
        'Unreal Engine' => ['clavicle_l', 'clavicle_r', 'spine_01', 'thigh_l', 'upperarm_l'],
        'Blender Rigify' => ['def-spine', 'org-spine', 'mch-', 'spine.001', 'upper_arm.l', 'shin.l'],
        'Character Creator' => ['cc_base_'],
        'Daz' => ['lthigh', 'rthigh', 'abdomenlower'],
        'VRoid or VRM' => ['j_bip_c_', 'j_adj_'],
    ];

    /**
     * @param  array<string, mixed>  $gltf
     * @param  resource|null  $handle
     * @return array<string, mixed>|null  null when the file carries no rig at all
     */
    public static function of(array $gltf, $handle, ?int $binOffset, ?float $size = null): ?array
    {
        $skins = $gltf['skins'] ?? [];
        $clips = self::clips($gltf, $handle, $binOffset, $size);

        if ($skins === [] && $clips === []) {
            return null;
        }

        $joints = [];

        foreach ($skins as $skin) {
            foreach ($skin['joints'] ?? [] as $joint) {
                $joints[$joint] = true;
            }
        }

        $weights = $handle === null || $binOffset === null
            ? null
            : self::weights($gltf, $handle, $binOffset);

        return array_filter([
            'bones' => count($joints),
            'skins' => count($skins),
            'naming' => self::familyOf($gltf, array_keys($joints)),
            'morph_targets' => self::morphTargets($gltf),
            'clips' => $clips,
            ...($weights ?? []),
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $gltf
     * @param  list<int>  $joints
     */
    private static function familyOf(array $gltf, array $joints): ?string
    {
        $nodes = $gltf['nodes'] ?? [];
        $names = '';

        foreach ($joints as $joint) {
            $names .= strtolower((string) ($nodes[$joint]['name'] ?? '')).'|';
        }

        if ($names === '') {
            return null;
        }

        foreach (self::FAMILIES as $family => $markers) {
            foreach ($markers as $marker) {
                if (str_contains($names, $marker)) {
                    return $family;
                }
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $gltf */
    private static function morphTargets(array $gltf): int
    {
        $most = 0;

        foreach ($gltf['meshes'] ?? [] as $mesh) {
            foreach ($mesh['primitives'] ?? [] as $primitive) {
                $most = max($most, count($primitive['targets'] ?? []));
            }
        }

        return $most;
    }

    /**
     * @param  array<string, mixed>  $gltf
     * @param  resource|null  $handle
     * @return list<array<string, mixed>>
     */
    private static function clips(array $gltf, $handle, ?int $binOffset, ?float $size): array
    {
        $accessors = $gltf['accessors'] ?? [];
        $roots = self::rootJoints($gltf);
        $clips = [];

        foreach ($gltf['animations'] ?? [] as $index => $animation) {
            $seconds = 0.0;
            $keys = 0;

            foreach ($animation['samplers'] ?? [] as $sampler) {
                $input = $accessors[$sampler['input'] ?? -1] ?? null;
                $seconds = max($seconds, (float) (($input['max'][0] ?? 0)));
                $keys += (int) ($input['count'] ?? 0);
            }

            $nodes = [];
            $moved = 0.0;

            foreach ($animation['channels'] ?? [] as $channel) {
                $node = $channel['target']['node'] ?? null;

                if ($node === null) {
                    continue;
                }

                $nodes[$node] = true;

                if (($channel['target']['path'] ?? '') === 'translation' && isset($roots[$node])) {
                    $moved = max($moved, self::travelOf(
                        $accessors[$animation['samplers'][$channel['sampler']]['output'] ?? -1] ?? null,
                        $gltf,
                        $handle,
                        $binOffset
                    ));
                }
            }

            $clips[] = array_filter([
                'index' => $index,
                'name' => $animation['name'] ?? null,
                'seconds' => round($seconds, 3),
                'nodes' => count($nodes),
                'keys' => $keys,
                'root_travel' => round($moved, 4),
                // A stride's worth of root movement, not a torso's sway. Measured
                // against the model's own size: 0.07 is a long way for a coin and
                // nothing for a dragon.
                'travels' => $size === null || $size <= 0 ? null : $moved > $size * 0.1,
            ], fn ($value) => $value !== null);
        }

        return $clips;
    }

    /**
     * The joints nothing else in the skeleton hangs from. A character that
     * walks forward moves one of these; one jogging on the spot does not.
     *
     * @param  array<string, mixed>  $gltf
     * @return array<int, true>
     */
    private static function rootJoints(array $gltf): array
    {
        $joints = [];

        foreach ($gltf['skins'] ?? [] as $skin) {
            foreach ($skin['joints'] ?? [] as $joint) {
                $joints[$joint] = true;
            }

            if (isset($skin['skeleton'])) {
                $joints[$skin['skeleton']] = true;
            }
        }

        // A skeleton's top bone nearly always hangs under an armature node, so
        // "no parent at all" finds nothing; what counts is no parent that is
        // itself a bone.
        $parent = [];

        foreach ($gltf['nodes'] ?? [] as $at => $node) {
            foreach ($node['children'] ?? [] as $child) {
                $parent[$child] = $at;
            }
        }

        return array_filter(
            $joints,
            fn ($_, $joint) => ! isset($parent[$joint]) || ! isset($joints[$parent[$joint]]),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * How far a root bone's track carries it, corner to corner. A track with
     * many keys that barely leaves its start is not travelling.
     *
     * @param  array<string, mixed>|null  $accessor
     * @param  array<string, mixed>  $gltf
     * @param  resource|null  $handle
     */
    private static function travelOf(?array $accessor, array $gltf, $handle, ?int $binOffset): float
    {
        if ($accessor === null || $handle === null || $binOffset === null) {
            return 0.0;
        }

        $values = self::read($accessor, $gltf, $handle, $binOffset, 3, 4096);
        $count = count($values);

        if ($count < 6) {
            return 0.0;
        }

        $spans = [];

        for ($axis = 0; $axis < 3; $axis++) {
            $low = $high = $values[$axis];

            for ($at = $axis; $at < $count; $at += 3) {
                $low = min($low, $values[$at]);
                $high = max($high, $values[$at]);
            }

            $spans[] = $high - $low;
        }

        return sqrt($spans[0] ** 2 + $spans[1] ** 2 + $spans[2] ** 2);
    }

    /**
     * The one pass that costs real work: every skinned vertex's four bones.
     *
     * @param  array<string, mixed>  $gltf
     * @param  resource  $handle
     * @return array<string, mixed>|null
     */
    private static function weights(array $gltf, $handle, int $binOffset): ?array
    {
        $tally = [
            'skinned_vertices' => 0,
            'unweighted_vertices' => 0,
            'unnormalised_vertices' => 0,
            'vertices_on_four_bones' => 0,
            'max_influences' => 0,
        ];

        $used = [];
        $read = false;

        foreach ($gltf['meshes'] ?? [] as $mesh) {
            foreach ($mesh['primitives'] ?? [] as $primitive) {
                $attributes = $primitive['attributes'] ?? [];

                if (! isset($attributes['JOINTS_0'], $attributes['WEIGHTS_0'])) {
                    continue;
                }

                $done = self::scan(
                    $gltf['accessors'][$attributes['JOINTS_0']] ?? null,
                    $gltf['accessors'][$attributes['WEIGHTS_0']] ?? null,
                    $gltf,
                    $handle,
                    $binOffset,
                    $tally,
                    $used
                );

                $read = $read || $done;
            }
        }

        if (! $read) {
            return null;
        }

        $tally['bones_used'] = count($used);

        return $tally;
    }

    /**
     * @param  array<string, mixed>|null  $jointsAccessor
     * @param  array<string, mixed>|null  $weightsAccessor
     * @param  array<string, mixed>  $gltf
     * @param  resource  $handle
     * @param  array<string, int>  $tally
     * @param  array<int, true>  $used
     */
    private static function scan(
        ?array $jointsAccessor,
        ?array $weightsAccessor,
        array $gltf,
        $handle,
        int $binOffset,
        array &$tally,
        array &$used
    ): bool {
        if ($jointsAccessor === null || $weightsAccessor === null) {
            return false;
        }

        $count = min((int) ($jointsAccessor['count'] ?? 0), (int) ($weightsAccessor['count'] ?? 0));
        $joints = self::cursor($jointsAccessor, $gltf, $binOffset, 4);
        $weights = self::cursor($weightsAccessor, $gltf, $binOffset, 4);

        if ($count === 0 || $joints === null || $weights === null) {
            return false;
        }

        $scale = self::SCALES[$weightsAccessor['componentType'] ?? 5126] ?? 1.0;
        $done = 0;

        // Walked together a batch at a time: holding either whole costs
        // hundreds of megabytes on a real character.
        while ($done < $count) {
            $batch = min($count - $done, 4096);

            $bones = self::batch($handle, $joints, $done, $batch);
            $pulls = self::batch($handle, $weights, $done, $batch);

            if ($bones === null || $pulls === null) {
                return $done > 0;
            }

            for ($vertex = 0; $vertex < $batch; $vertex++) {
                $total = 0.0;
                $pulling = 0;

                for ($slot = 1; $slot <= 4; $slot++) {
                    $at = $vertex * 4 + $slot;
                    $weight = $pulls[$at] / $scale;

                    if ($weight <= 1e-6) {
                        continue;
                    }

                    $total += $weight;
                    $pulling++;
                    $used[(int) $bones[$at]] = true;
                }

                $tally['skinned_vertices']++;
                $tally['max_influences'] = max($tally['max_influences'], $pulling);

                if ($pulling === 4) {
                    $tally['vertices_on_four_bones']++;
                }

                if ($total <= 1e-6) {
                    $tally['unweighted_vertices']++;
                } elseif (abs($total - 1.0) > 0.02) {
                    $tally['unnormalised_vertices']++;
                }
            }

            $done += $batch;
        }

        return true;
    }

    /**
     * Where an accessor's elements start and how to unpack one.
     *
     * @param  array<string, mixed>  $accessor
     * @param  array<string, mixed>  $gltf
     * @return array{at: int, element: int, code: string, components: int}|null
     */
    private static function cursor(array $accessor, array $gltf, int $binOffset, int $components): ?array
    {
        $view = $gltf['bufferViews'][$accessor['bufferView'] ?? -1] ?? null;
        $type = (int) ($accessor['componentType'] ?? 0);
        $size = self::SIZES[$type] ?? 0;

        // Only the ordinary case: whole, tightly packed elements in buffer 0.
        if ($view === null || ($view['buffer'] ?? 0) !== 0 || $size === 0 || isset($accessor['sparse'])) {
            return null;
        }

        $element = $size * $components;

        if ((int) ($view['byteStride'] ?? $element) !== $element) {
            return null;
        }

        return [
            'at' => $binOffset + (int) ($view['byteOffset'] ?? 0) + (int) ($accessor['byteOffset'] ?? 0),
            'element' => $element,
            'components' => $components,
            'code' => match ($type) {
                5121 => 'C',
                5123 => 'v',
                5125 => 'V',
                default => 'g',
            },
        ];
    }

    /**
     * @param  resource  $handle
     * @param  array{at: int, element: int, code: string, components: int}  $cursor
     * @return array<int, float|int>|null  1-indexed, as unpack returns it
     */
    private static function batch($handle, array $cursor, int $from, int $count): ?array
    {
        if (fseek($handle, $cursor['at'] + $from * $cursor['element']) !== 0) {
            return null;
        }

        $bytes = (string) fread($handle, $count * $cursor['element']);

        if (strlen($bytes) < $count * $cursor['element']) {
            return null;
        }

        return unpack($cursor['code'].($count * $cursor['components']), $bytes) ?: null;
    }

    /**
     * @param  array<string, mixed>  $accessor
     * @param  array<string, mixed>  $gltf
     * @param  resource  $handle
     * @return list<float|int>
     */
    private static function read(
        array $accessor,
        array $gltf,
        $handle,
        int $binOffset,
        int $components,
        int $limit
    ): array {
        $view = $gltf['bufferViews'][$accessor['bufferView'] ?? -1] ?? null;
        $type = (int) ($accessor['componentType'] ?? 0);
        $size = self::SIZES[$type] ?? 0;
        $count = min((int) ($accessor['count'] ?? 0), $limit);

        // Only the ordinary case: whole, tightly packed elements in buffer 0.
        if ($view === null || ($view['buffer'] ?? 0) !== 0 || $size === 0 || $count === 0
            || isset($accessor['sparse'])) {
            return [];
        }

        $element = $size * $components;
        $stride = (int) ($view['byteStride'] ?? $element);

        if ($stride !== $element) {
            return [];
        }

        $at = $binOffset + (int) ($view['byteOffset'] ?? 0) + (int) ($accessor['byteOffset'] ?? 0);

        if (fseek($handle, $at) !== 0) {
            return [];
        }

        $code = match ($type) {
            5121 => 'C',
            5123 => 'v',
            5125 => 'V',
            default => 'g',
        };

        $out = [];
        $remaining = $count;

        while ($remaining > 0) {
            $batch = min($remaining, 4096);
            $bytes = (string) fread($handle, $batch * $element);

            if (strlen($bytes) < $batch * $element) {
                return $out;
            }

            foreach (unpack($code.($batch * $components), $bytes) as $value) {
                $out[] = $value;
            }

            $remaining -= $batch;
        }

        return $out;
    }
}
