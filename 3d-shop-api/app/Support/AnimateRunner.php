<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

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
        private readonly ContainerBroker $broker = new ContainerBroker,
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
        ?string $entry = null,
        array $about = []
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
            self::jobFor($clip, $frames, $size, $camera, $passes, $format, $entry),
            JSON_PRETTY_PRINT
        ));

        $answer = $this->broker->run([
            'kind' => ContainerJob::ANIMATE,
            'scratch' => ContainerBroker::scratchName($scratchDir),
            'source' => basename($bundleDir ?? $modelPath),
            'bundle' => $bundleDir !== null,
        ], $this->timeoutSeconds + 120, $about);

        Log::channel('render')->debug('Clip harness exited.', [
            'status' => $answer['status'],
            'exit_code' => $answer['exit'] ?? null,
            'stderr' => substr(trim((string) ($answer['error'] ?? '')), -600) ?: null,
            'reason' => $answer['reason'] ?? null,
        ]);

        $resultFile = $outDir.DIRECTORY_SEPARATOR.'result.json';

        if (! is_file($resultFile)) {
            return [
                'status' => 'failed',
                'reason' => 'The clip harness produced no result.',
                'retryable' => true,
                'exit_code' => $answer['exit'] ?? null,
                'stderr' => substr(trim((string) ($answer['error'] ?? '')), -600),
            ];
        }

        return json_decode((string) file_get_contents($resultFile), true) ?? [
            'status' => 'failed',
            'reason' => 'The clip result could not be read.',
            'retryable' => true,
        ];
    }

    /**
     * What the container is asked for. Separate from run() so the suite can check
     * it without starting a container.
     *
     * @param  array{position: list<float>, target?: list<float>, up?: list<float>, fov?: float}  $camera
     * @param  list<string>  $passes
     * @return array<string, mixed>
     */
    public static function jobFor(
        int $clip,
        int $frames,
        int $size,
        array $camera,
        array $passes,
        string $format,
        ?string $entry = null
    ): array {
        return array_filter([
            'clip' => $clip,
            'frames' => $frames,
            'output' => ['width' => $size, 'height' => $size],
            'passes' => array_values(array_intersect($passes, self::PASSES)),
            'camera' => $camera,
            'format' => $format,
            'entry' => $entry,
        ], fn ($value) => $value !== null);
    }
}
