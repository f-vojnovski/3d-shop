<?php

namespace App\Support;

/**
 * A turntable a seller can ask for instead of framing every shot by hand.
 *
 * Constants, not measurements: the harness fits and centres every model before
 * placing the camera, so one position frames any size of model, and slot 3 is
 * 135 degrees for every product and every version of it.
 */
class StandardAngles
{
    public const COUNT = 8;

    /** The viewer's own opening distance, so the framing matches the canvas. */
    private const RADIUS = 5.0;

    private const FOV = 75;

    /** Slightly above the horizon: level-on hides the roof of anything. */
    private const ELEVATION_DEGREES = 18.0;

    /** View 1 is the thumbnail, and square-on is the flattest angle there is. */
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
