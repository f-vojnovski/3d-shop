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

    /**
     * The only writable place inside, and nothing may be run from it: a parser
     * talked into writing a file has then written it somewhere it cannot be
     * executed from, and cannot gain anything by owning it.
     */
    public const TMPFS = '/tmp:rw,nosuid,noexec,size=512m';

    /** @return list<string> */
    public static function confinement(string $memory, string $cpus): array
    {
        return [
            '--network=none',
            '--cap-drop=ALL',
            // Nothing inside can come to hold more than it started with, so a
            // dropped capability stays dropped however the process forks.
            '--security-opt=no-new-privileges',
            // Everything the job needs to write is mounted or on the tmpfs, so
            // the image itself is nobody's to change.
            '--read-only',
            "--memory={$memory}",
            "--cpus={$cpus}",
            '--pids-limit='.self::PIDS,
            '--tmpfs', self::TMPFS,
        ];
    }
}
