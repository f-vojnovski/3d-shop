<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

class RenderRunner
{
    public const IMAGE = '3dshop-render';

    public function __construct(
        private readonly int $timeoutSeconds = 180,
        private readonly ContainerBroker $broker = new ContainerBroker,
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
        ?string $bundleDir = null,
        array $about = []
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

        $answer = $this->broker->run([
            'kind' => ContainerJob::RENDER,
            'scratch' => ContainerBroker::scratchName($scratchDir),
            'source' => basename($bundleDir ?? $modelPath),
            'bundle' => $bundleDir !== null,
        ], $this->timeoutSeconds + 120, $about + [
            'source_bytes' => $request['source']['bytes'] ?? null,
            'triangles' => ($request['source']['faces'] ?? 0) ?: null,
        ]);

        Log::channel('render')->debug('Renderer exited.', [
            'status' => $answer['status'],
            'exit_code' => $answer['exit'] ?? null,
            'stderr' => substr(trim((string) ($answer['error'] ?? '')), -600) ?: null,
            'reason' => $answer['reason'] ?? null,
        ]);

        $resultFile = $outDir.DIRECTORY_SEPARATOR.'result.json';

        if (! is_file($resultFile)) {
            return [
                'status' => 'failed',
                'reason' => 'The renderer produced no result.',
                'retryable' => true,
                'exit_code' => $answer['exit'] ?? null,
                'stderr' => substr(trim((string) ($answer['error'] ?? '')), -600),
            ];
        }

        return json_decode((string) file_get_contents($resultFile), true) ?? [
            'status' => 'failed',
            'reason' => 'The renderer result could not be read.',
            'retryable' => true,
        ];
    }
}
