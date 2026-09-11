<?php

namespace App\Support;

/**
 * What an uploaded file is opened inside. The parsers are not trusted; the
 * container is the boundary, so trimming any of these is a security change
 * rather than a tidy-up.
 *
 * One definition, because the renderer and the converter confining a file
 * differently is a difference nobody would notice until it mattered.
 */
class Sandbox
{
    public const PIDS = 256;

    public const TMPFS = '/tmp:rw,size=512m';

    /** @return list<string> */
    public static function confinement(string $memory, string $cpus): array
    {
        return [
            '--network=none',
            '--cap-drop=ALL',
            "--memory={$memory}",
            "--cpus={$cpus}",
            '--pids-limit='.self::PIDS,
            '--tmpfs', self::TMPFS,
        ];
    }
}
