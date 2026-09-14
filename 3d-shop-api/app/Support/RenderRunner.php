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
        $outDir = Sandbox::outbox($scratchDir);

        if ($outDir === null) {
            return [
                'status' => 'failed',
                'reason' => 'The renderer had nowhere to write.',
                'retryable' => true,
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
            $stderr = substr(trim((string) ($answer['error'] ?? '')), -600);

            return [
                'status' => 'failed',
                'reason' => self::wroteNothing($answer['reason'] ?? null, $stderr, $answer['exit'] ?? null),
                'retryable' => true,
                'exit_code' => $answer['exit'] ?? null,
                'stderr' => $stderr,
            ];
        }

        $result = json_decode((string) file_get_contents($resultFile), true) ?? [
            'status' => 'failed',
            'reason' => 'The renderer result could not be read.',
            'retryable' => true,
        ];

        // The tag names the renderer asked for; only the broker saw which answered.
        if (is_array($result['renderer'] ?? null)) {
            $result['renderer']['image'] = $answer['image'] ?? null;
        }

        return $result;
    }

    /**
     * A container that wrote no result has already said why on stderr. Without
     * this the one failure that explains nothing is the one nobody can debug.
     */
    private static function wroteNothing(?string $brokerReason, string $stderr, ?int $exit): string
    {
        if ($brokerReason !== null) {
            return $brokerReason;
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $stderr))));
        // Node prints the exception well above the stack and the trailing banner.
        $named = array_values(array_filter($lines, fn (string $line) => preg_match('/^[A-Za-z]*Error\b/', $line) === 1));
        $said = $named[0] ?? $lines[0] ?? null;

        return $said === null
            ? sprintf('The renderer exited %s without writing a result or saying why.', $exit ?? '?')
            : sprintf('The renderer exited %s without writing a result: %s', $exit ?? '?', substr($said, 0, 220));
    }
}
