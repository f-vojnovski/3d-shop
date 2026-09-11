<?php

namespace App\Support;

use App\Models\ProductFile;
use Illuminate\Support\Collection;

/**
 * Whether a product's formats are the same model. Every format is measured
 * separately, so a seller uploading a clean .glb and a mangled .fbx shows a
 * buyer whichever tab they happen to open.
 */
class FormatAgreement
{
    /** Face counts are exact; a sliver of drift in bounds is rounding. */
    private const BOUNDS_TOLERANCE = 0.01;

    /**
     * @param  Collection<int, ProductFile>  $deliverables
     * @return array{compared: list<string>, agrees: bool, differences: list<string>}|null
     */
    public static function of(Collection $deliverables): ?array
    {
        // Sorted here rather than trusting the caller: the comparison appears on
        // a listing and must not reorder itself between page loads.
        $measured = $deliverables
            ->filter(fn (ProductFile $file) => ($file->facts()['faces'] ?? null) !== null)
            ->sortBy(fn (ProductFile $file) => (string) $file->format)
            ->values();

        if ($measured->count() < 2) {
            return null;
        }

        $differences = array_values(array_filter([
            self::faces($measured),
            self::bounds($measured),
        ]));

        return [
            'compared' => $measured->map(fn (ProductFile $file) => (string) $file->format)->all(),
            'agrees' => $differences === [],
            'differences' => $differences,
        ];
    }

    /**
     * Only comparable when the formats agree on what a face is. Most exporters
     * triangulate on the way out, so an honest pair of one .obj of quads and
     * one .glb differs by a factor of two by construction — the same trap the
     * vertex comparison was dropped for.
     *
     * @param  Collection<int, ProductFile>  $measured
     */
    private static function faces(Collection $measured): ?string
    {
        $topologies = $measured
            ->map(fn (ProductFile $file) => (string) ($file->facts()['topology'] ?? MeshFacts::UNKNOWN))
            ->unique();

        if ($topologies->count() > 1 || $topologies->first() === MeshFacts::UNKNOWN) {
            return null;
        }

        $counts = $measured
            ->mapWithKeys(fn (ProductFile $file) => [$file->format => (int) $file->facts()['faces']])
            ->all();

        if (count(array_unique($counts)) === 1) {
            return null;
        }

        return 'Face counts differ: '.self::listOf($counts).'.';
    }

    /**
     * Vertices are deliberately not compared: .stl repeats a shared corner per
     * triangle, so an honest pair disagrees by construction.
     *
     * @param  Collection<int, ProductFile>  $measured
     */
    private static function bounds(Collection $measured): ?string
    {
        $sizes = [];

        foreach ($measured as $file) {
            $size = $file->facts()['bounds']['size'] ?? null;

            if (! is_array($size) || count($size) !== 3) {
                return null;
            }

            $sizes[$file->format] = array_map(fn ($value) => (float) $value, $size);
        }

        $first = reset($sizes);

        foreach ($sizes as $size) {
            for ($axis = 0; $axis < 3; $axis++) {
                if (abs($size[$axis] - $first[$axis]) > self::BOUNDS_TOLERANCE) {
                    return 'Sizes differ: '.self::listOf(array_map(
                        fn (array $each) => implode(' × ', array_map(
                            fn (float $value) => rtrim(rtrim(number_format($value, 2), '0'), '.'),
                            $each
                        )),
                        $sizes
                    )).'.';
                }
            }
        }

        return null;
    }

    /** @param  array<string, int|string>  $values */
    private static function listOf(array $values): string
    {
        $parts = [];

        foreach ($values as $format => $value) {
            $parts[] = '.'.$format.' '.(is_int($value) ? number_format($value) : $value);
        }

        return implode(', ', $parts);
    }
}
