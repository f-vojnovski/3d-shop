<?php

namespace App\Support;

/**
 * A turntable a seller can ask for instead of framing every shot by hand.
 *
 * These are constants rather than measurements because the harness fits every
 * model to the same size and centres it before placing the camera, so a
 * position here frames a 4-metre car and a 4-centimetre toy identically. That
 * also makes the slots durable: view 3 is 135 degrees for every product and
 * every version of it, so a replaced file can be compared with what it
 * replaced, shot for shot.
 */
class StandardAngles
{
    public const COUNT = 8;

    /** The viewer's own opening distance, so the framing matches the canvas. */
    private const RADIUS = 5.0;

    private const FOV = 75;

    /** Slightly above the horizon: level-on hides the roof of anything. */
    private const ELEVATION_DEGREES = 18.0;

    /**
     * Starts on a corner rather than square-on. View 1 becomes the thumbnail
     * and the opening shot, and an axis-aligned view is the flattest way to
     * photograph anything; the square-on views are still in the set, further
     * round.
     */
    private const START_DEGREES = 45.0;

    /** @return list<array{position: list<float>, target: list<float>, fov: int, origin: string, slot: int}> */
    public static function set(): array
    {
        $elevation = deg2rad(self::ELEVATION_DEGREES);
        $angles = [];

        for ($slot = 0; $slot < self::COUNT; $slot++) {
            $azimuth = deg2rad(self::START_DEGREES + $slot * (360 / self::COUNT));

            $angles[] = [
                'position' => [
                    round(self::RADIUS * cos($elevation) * sin($azimuth), 6),
                    round(self::RADIUS * sin($elevation), 6),
                    round(self::RADIUS * cos($elevation) * cos($azimuth), 6),
                ],
                'target' => [0.0, 0.0, 0.0],
                'fov' => self::FOV,
                'origin' => 'standard',
                'slot' => $slot,
            ];
        }

        return $angles;
    }
}
