<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
     * Runs the render container over one job and returns its result document.
     * The model is mounted read-only rather than copied, so a large file costs
     * nothing to hand over.
     */
    public function run(array $request, string $modelAbsolutePath, string $stagingRelative): array
    {
        $disk = Storage::disk('models');
        $disk->put("{$stagingRelative}/job.json", json_encode($request, JSON_PRETTY_PRINT));
        $disk->makeDirectory("{$stagingRelative}/out");

        $jobFile = $disk->path("{$stagingRelative}/job.json");
        $outDir = $disk->path("{$stagingRelative}/out");

        $process = new Process([
            'docker', 'run', '--rm',
            '--network=none', '--cap-drop=ALL',
            "--memory={$this->memory}", "--cpus={$this->cpus}",
            '--pids-limit=256', '--tmpfs', '/tmp:rw,size=512m',
            '-e', "RENDER_TIMEOUT={$this->timeoutSeconds}",
            '-v', $this->hostPath($modelAbsolutePath).':/in/model:ro',
            '-v', $this->hostPath($jobFile).':/in/job.json:ro',
            '-v', $this->hostPath($outDir).':/out',
            self::IMAGE,
        ]);

        $process->setTimeout($this->timeoutSeconds + 60);
        $process->run();

        Log::channel('render')->debug('Renderer exited.', [
            'exit_code' => $process->getExitCode(),
            'stderr' => substr(trim($process->getErrorOutput()), -600) ?: null,
        ]);

        $resultPath = "{$stagingRelative}/out/result.json";

        if (! $disk->exists($resultPath)) {
            return [
                'status' => 'failed',
                'reason' => 'The renderer produced no result.',
                'exit_code' => $process->getExitCode(),
                'stderr' => substr(trim($process->getErrorOutput()), -600),
            ];
        }

        return json_decode($disk->get($resultPath), true) ?? [
            'status' => 'failed',
            'reason' => 'The renderer result could not be read.',
        ];
    }

    /**
     * Docker on Windows wants //d/path, not D:\path. Isolated here because it
     * is the one place the host platform leaks into the pipeline.
     */
    private function hostPath(string $absolute): string
    {
        if (! preg_match('/^([A-Za-z]):[\\\\\\/](.*)$/', $absolute, $matches)) {
            return $absolute;
        }

        return '//'.strtolower($matches[1]).'/'.str_replace('\\', '/', $matches[2]);
    }
}
