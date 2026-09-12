<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Draws one animation clip as a moving picture, so a buyer can watch a model
 * move without being handed the motion itself.
 *
 * Runs the render image on its own entrypoint. Same confinement as a render and
 * the same three.js, but nothing it does can reach the attested still path.
 */
class AnimateRunner
{
    /** Every pass the page knows how to paint. */
    public const PASSES = ['shaded', 'influence', 'bones'];

    public function __construct(
        private readonly int $timeoutSeconds = 600,
        private readonly string $memory = '3g',
        private readonly string $cpus = '2',
    ) {}

    /**
     * @param  array{position: list<float>, target?: list<float>, up?: list<float>, fov?: float}  $camera
     * @param  list<string>  $passes
     * @return array{status: string, files?: list<array>, clip?: array, clips?: list<array>, reason?: string, retryable?: bool}
     */
    public function run(
        int $clip,
        int $frames,
        int $size,
        array $camera,
        array $passes,
        string $format,
        string $modelPath,
        string $scratchDir,
        ?string $bundleDir = null,
        ?string $entry = null
    ): array {
        $jobFile = $scratchDir.DIRECTORY_SEPARATOR.'job.json';
        $outDir = $scratchDir.DIRECTORY_SEPARATOR.'out';

        if (! is_dir($outDir) && ! mkdir($outDir, 0775, true) && ! is_dir($outDir)) {
            return [
                'status' => 'failed',
                'reason' => 'The clip had nowhere to write.',
                'retryable' => true,
            ];
        }

        file_put_contents($jobFile, json_encode(
            array_filter([
                'clip' => $clip,
                'frames' => $frames,
                'output' => ['width' => $size, 'height' => $size],
                'passes' => array_values(array_intersect($passes, self::PASSES)),
                'camera' => $camera,
                'format' => $format,
                'entry' => $entry,
            ], fn ($value) => $value !== null),
            JSON_PRETTY_PRINT
        ));

        $process = new Process($this->commandFor($modelPath, $jobFile, $outDir, $bundleDir));

        $process->setTimeout($this->timeoutSeconds + 60);
        $process->run();

        Log::channel('render')->debug('Clip harness exited.', [
            'exit_code' => $process->getExitCode(),
            'stderr' => substr(trim($process->getErrorOutput()), -600) ?: null,
        ]);

        $resultFile = $outDir.DIRECTORY_SEPARATOR.'result.json';

        if (! is_file($resultFile)) {
            return [
                'status' => 'failed',
                'reason' => 'The clip harness produced no result.',
                'retryable' => true,
                'exit_code' => $process->getExitCode(),
                'stderr' => substr(trim($process->getErrorOutput()), -600),
            ];
        }

        return json_decode((string) file_get_contents($resultFile), true) ?? [
            'status' => 'failed',
            'reason' => 'The clip result could not be read.',
            'retryable' => true,
        ];
    }

    /**
     * Separated for the same reason as RenderRunner::commandFor(): the suite
     * substitutes this class wherever a clip is drawn, leaving the argv the one
     * part of the sandbox nothing else sees.
     *
     * @return list<string>
     */
    public function commandFor(string $modelPath, string $jobFile, string $outDir, ?string $bundleDir = null): array
    {
        $source = $bundleDir === null
            ? $this->hostPath($modelPath).':/in/model:ro'
            : $this->hostPath($bundleDir).':/in/bundle:ro';

        return [
            'docker', 'run', '--rm',
            ...Sandbox::confinement($this->memory, $this->cpus),
            '-e', "ANIMATE_TIMEOUT={$this->timeoutSeconds}",
            '--entrypoint', 'node',
            '-v', $source,
            '-v', $this->hostPath($jobFile).':/in/job.json:ro',
            '-v', $this->hostPath($outDir).':/out',
            RenderRunner::IMAGE,
            '/app/animate.mjs',
        ];
    }

    /** Docker on Windows wants //d/path, not D:\path. */
    private function hostPath(string $absolute): string
    {
        $absolute = HostPaths::translate($absolute);

        if (! preg_match('/^([A-Za-z]):[\\\\\\/](.*)$/', $absolute, $matches)) {
            return $absolute;
        }

        return '//'.strtolower($matches[1]).'/'.str_replace('\\', '/', $matches[2]);
    }
}
