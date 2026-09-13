<?php

namespace App\Support;

use RuntimeException;

/**
 * Turns a job into the only `docker run` it is allowed to become.
 *
 * Every mount is built here from the scratch directory and a fixed name inside
 * it. Nothing a request carries reaches an argument, so the worst a request can
 * do is name a different directory of its own.
 */
class ContainerCommand
{
    /**
     * @return list<string>
     *
     * @throws RuntimeException when a mount would leave the scratch root
     */
    public static function for(ContainerJob $job, string $privateRoot): array
    {
        $settings = $job->settings();
        $scratch = self::contained($privateRoot, $job->scratch);
        $source = self::contained($scratch, $job->source);
        $out = self::contained($scratch, 'out');

        $command = ['docker', 'run', '--rm'];
        $command = [...$command, ...Sandbox::confinement($settings['memory'], $settings['cpus'])];

        if ($settings['env'] !== null) {
            $command = [...$command, '-e', $settings['env'].'='.$settings['timeout']];
        }

        if ($settings['entrypoint'] !== null) {
            $command = [...$command, '--entrypoint', $settings['entrypoint']];
        }

        $command = [
            ...$command,
            '-v', self::host($source).':'.($job->bundle ? '/in/bundle' : '/in/model').':ro',
            '-v', self::host($out).':/out',
        ];

        // A conversion is handed its arguments; everything else reads job.json.
        if ($job->kind === ContainerJob::CONVERT) {
            $inside = $job->bundle ? '/in/bundle/'.$job->entry : '/in/model';

            return [...$command, RenderRunner::IMAGE, 'export', $inside, '/out/converted.glb'];
        }

        $command = [...$command, '-v', self::host(self::contained($scratch, 'job.json')).':/in/job.json:ro'];

        return [...$command, RenderRunner::IMAGE, ...$settings['arguments']];
    }

    /**
     * The resolved path, only if it is still inside its parent.
     *
     * The worker writes into the scratch directory, so it can leave a symlink
     * there, and Docker resolves a mount source on the host before it binds it.
     * A name that looks plain is not enough on its own.
     */
    private static function contained(string $parent, string $name): string
    {
        $parentReal = realpath($parent);

        if ($parentReal === false) {
            throw new RuntimeException("No such directory: {$parent}");
        }

        $joined = $parentReal.DIRECTORY_SEPARATOR.$name;
        $real = realpath($joined);

        if ($real === false) {
            // Written by the container rather than for it, so it may not exist
            // yet. The name is a single plain segment, so the join is the path.
            return $joined;
        }

        $prefix = $parentReal.DIRECTORY_SEPARATOR;

        if (! str_starts_with($real, $prefix)) {
            throw new RuntimeException("That path leaves its directory: {$name}");
        }

        return $real;
    }

    /** Docker resolves `-v` against the host, which knows nothing of this container. */
    private static function host(string $absolute): string
    {
        $absolute = HostPaths::translate($absolute);

        if (! preg_match('/^([A-Za-z]):[\\\\\\/](.*)$/', $absolute, $matches)) {
            return $absolute;
        }

        return '//'.strtolower($matches[1]).'/'.str_replace('\\', '/', $matches[2]);
    }
}
