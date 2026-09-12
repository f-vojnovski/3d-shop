<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Cuts a deliverable down to the share of detail the seller chose, so buyers
 * have something to aim a camera at without being handed the model.
 *
 * Runs the render image on its own entrypoint. Same confinement as a render and
 * the same three.js, but nothing it does can reach the attested still path.
 */
class ProxyRunner
{
    public function __construct(
        private readonly int $timeoutSeconds = 180,
        private readonly string $memory = '2g',
        private readonly string $cpus = '2',
    ) {}

    /**
     * @return array{status: string, file?: string, bytes?: int, triangles?: array, reason?: string, retryable?: bool}
     */
    public function run(
        float $ratio,
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
                'reason' => 'The simplifier had nowhere to write.',
                'retryable' => true,
            ];
        }

        file_put_contents($jobFile, json_encode(
            array_filter([
                'ratio' => $ratio,
                'format' => $format,
                'entry' => $entry,
            ], fn ($value) => $value !== null),
            JSON_PRETTY_PRINT
        ));

        $process = new Process($this->commandFor($modelPath, $jobFile, $outDir, $bundleDir));

        $process->setTimeout($this->timeoutSeconds + 60);
        $process->run();

        Log::channel('render')->debug('Simplifier exited.', [
            'exit_code' => $process->getExitCode(),
            'stderr' => substr(trim($process->getErrorOutput()), -600) ?: null,
        ]);

        $resultFile = $outDir.DIRECTORY_SEPARATOR.'result.json';

        if (! is_file($resultFile)) {
            return [
                'status' => 'failed',
                'reason' => 'The simplifier produced no result.',
                'retryable' => true,
                'exit_code' => $process->getExitCode(),
                'stderr' => substr(trim($process->getErrorOutput()), -600),
            ];
        }

        return json_decode((string) file_get_contents($resultFile), true) ?? [
            'status' => 'failed',
            'reason' => 'The simplifier result could not be read.',
            'retryable' => true,
        ];
    }

    /**
     * Separate from run() for the same reason as RenderRunner::commandFor():
     * the suite substitutes this class, so argv is the one part of the sandbox
     * nothing else would see.
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
            '-e', "PROXY_TIMEOUT={$this->timeoutSeconds}",
            '-v', $source,
            '-v', $this->hostPath($jobFile).':/in/job.json:ro',
            '-v', $this->hostPath($outDir).':/out',
            '--entrypoint', 'node',
            RenderRunner::IMAGE,
            '/app/proxy.mjs',
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
