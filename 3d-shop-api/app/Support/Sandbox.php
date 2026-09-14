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

    /**
     * The one directory a container writes into, created before it starts.
     *
     * World-writable deliberately: `--cap-drop=ALL` takes CAP_DAC_OVERRIDE away
     * from root inside the container, so it obeys the mode bits like anyone
     * else, and the host user that made this directory is not its owner.
     *
     * @return string|null the path, or null if it could not be made
     */
    public static function outbox(string $scratchDir): ?string
    {
        $out = $scratchDir.DIRECTORY_SEPARATOR.'out';

        if (! is_dir($out) && ! mkdir($out, 0777, true) && ! is_dir($out)) {
            return null;
        }

        // mkdir's mode is masked by the umask, so say it again plainly.
        @chmod($out, 0777);

        return $out;
    }

    /** @return list<string> */
    public static function confinement(string $memory, string $cpus): array
    {
        $flags = [
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
        ];

        $cpuset = self::cpuset($cpus);

        if ($cpuset !== null) {
            $flags[] = "--cpuset-cpus={$cpuset}";
        }

        return [...$flags, '--tmpfs', self::TMPFS];
    }

    /**
     * `--cpus` is only a time budget: the container still sees every core, and
     * SwiftShader starts a render thread per core it sees. Offset by pid so two
     * workers do not pin the same block.
     */
    private static function cpuset(string $cpus): ?string
    {
        $want = max(1, (int) $cpus);
        $online = self::onlineCores();

        if ($online === null || $online < $want) {
            return null;
        }

        $blocks = intdiv($online, $want);
        $first = (getmypid() % $blocks) * $want;

        return $first.'-'.($first + $want - 1);
    }

    private static function onlineCores(): ?int
    {
        $windows = getenv('NUMBER_OF_PROCESSORS');

        if ($windows !== false && (int) $windows > 0) {
            return (int) $windows;
        }

        $cpuinfo = @file_get_contents('/proc/cpuinfo');

        if ($cpuinfo === false) {
            return null;
        }

        $count = preg_match_all('/^processor\s*:/m', $cpuinfo);

        return $count > 0 ? $count : null;
    }
}
