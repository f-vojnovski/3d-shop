<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class RenderRunner
{
    public const IMAGE = '3dshop-render';

    public function __construct(
        private readonly int $timeoutSeconds = 180,
        private readonly string $memory = '2g',
        private readonly string $cpus = '2',
    ) {}

    /**
     * Runs the render container over one job. Both paths are on the worker's own
     * scratch disk, so the container keeps --network=none and the worker is the
     * only thing that talks to object storage.
     */
    public function run(
        array $request,
        string $modelPath,
        string $scratchDir,
        ?string $bundleDir = null
    ): array {
        $jobFile = $scratchDir.DIRECTORY_SEPARATOR.'job.json';
        $outDir = $scratchDir.DIRECTORY_SEPARATOR.'out';

        if (! is_dir($outDir) && ! mkdir($outDir, 0775, true) && ! is_dir($outDir)) {
            return [
                'status' => 'failed',
                'reason' => 'The renderer had nowhere to write.',
                'retryable' => true,
                'scratch' => $outDir,
            ];
        }

        file_put_contents($jobFile, json_encode($request, JSON_PRETTY_PRINT));

        $process = new Process($this->commandFor($modelPath, $jobFile, $outDir, $bundleDir));

        $process->setTimeout($this->timeoutSeconds + 60);
        $process->run();

        Log::channel('render')->debug('Renderer exited.', [
            'exit_code' => $process->getExitCode(),
            'stderr' => substr(trim($process->getErrorOutput()), -600) ?: null,
        ]);

        $resultFile = $outDir.DIRECTORY_SEPARATOR.'result.json';

        if (! is_file($resultFile)) {
            return [
                'status' => 'failed',
                'reason' => 'The renderer produced no result.',
                'retryable' => true,
                'exit_code' => $process->getExitCode(),
                'stderr' => substr(trim($process->getErrorOutput()), -600),
            ];
        }

        return json_decode((string) file_get_contents($resultFile), true) ?? [
            'status' => 'failed',
            'reason' => 'The renderer result could not be read.',
            'retryable' => true,
        ];
    }

    /**
     * Separate from run() so a test can read the confinement back: the suite
     * substitutes this class wherever a render happens, leaving the argv the
     * one part of the sandbox nothing else sees.
     *
     * @return list<string>
     */
    public function commandFor(string $modelPath, string $jobFile, string $outDir, ?string $bundleDir = null): array
    {
        // A bundle arrives as a folder so the model keeps its neighbours; a
        // bare model is one file. Read-only either way: the renderer never
        // writes to what it was given.
        $source = $bundleDir === null
            ? $this->hostPath($modelPath).':/in/model:ro'
            : $this->hostPath($bundleDir).':/in/bundle:ro';

        return [
            'docker', 'run', '--rm',
            ...Sandbox::confinement($this->memory, $this->cpus),
            '-e', "RENDER_TIMEOUT={$this->timeoutSeconds}",
            '-v', $source,
            '-v', $this->hostPath($jobFile).':/in/job.json:ro',
            '-v', $this->hostPath($outDir).':/out',
            self::IMAGE,
        ];
    }

    /** Docker on Windows wants //d/path, not D:\path. */
    private function hostPath(string $absolute): string
    {
        if (! preg_match('/^([A-Za-z]):[\\\\\\/](.*)$/', $absolute, $matches)) {
            return $absolute;
        }

        return '//'.strtolower($matches[1]).'/'.str_replace('\\', '/', $matches[2]);
    }
}
