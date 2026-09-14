<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

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
        private readonly ContainerBroker $broker = new ContainerBroker,
    ) {}

    /**
     * @return array{status: string, file?: string, bytes?: int, triangles?: array, reason?: string, retryable?: bool}
     */
    public function run(
        float $ratio,
        string $method,
        string $format,
        string $modelPath,
        string $scratchDir,
        ?string $bundleDir = null,
        ?string $entry = null
    ): array {
        $jobFile = $scratchDir.DIRECTORY_SEPARATOR.'job.json';
        $outDir = Sandbox::outbox($scratchDir);

        if ($outDir === null) {
            return [
                'status' => 'failed',
                'reason' => 'The simplifier had nowhere to write.',
                'retryable' => true,
            ];
        }

        file_put_contents($jobFile, json_encode(
            array_filter([
                'ratio' => $ratio,
                'method' => $method,
                'format' => $format,
                'entry' => $entry,
            ], fn ($value) => $value !== null),
            JSON_PRETTY_PRINT
        ));

        $answer = $this->broker->run([
            'kind' => ContainerJob::PROXY,
            'scratch' => ContainerBroker::scratchName($scratchDir),
            'source' => basename($bundleDir ?? $modelPath),
            'bundle' => $bundleDir !== null,
        ], $this->timeoutSeconds + 120);

        Log::channel('render')->debug('Simplifier exited.', [
            'status' => $answer['status'],
            'exit_code' => $answer['exit'] ?? null,
            'stderr' => substr(trim((string) ($answer['error'] ?? '')), -600) ?: null,
            'reason' => $answer['reason'] ?? null,
        ]);

        $resultFile = $outDir.DIRECTORY_SEPARATOR.'result.json';

        if (! is_file($resultFile)) {
            return [
                'status' => 'failed',
                'reason' => 'The simplifier produced no result.',
                'retryable' => true,
                'exit_code' => $answer['exit'] ?? null,
                'stderr' => substr(trim((string) ($answer['error'] ?? '')), -600),
            ];
        }

        return json_decode((string) file_get_contents($resultFile), true) ?? [
            'status' => 'failed',
            'reason' => 'The simplifier result could not be read.',
            'retryable' => true,
        ];
    }
}
