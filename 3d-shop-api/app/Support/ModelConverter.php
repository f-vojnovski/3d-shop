<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Turns a format PHP cannot parse into a glb, so one measuring and rendering
 * path serves every format. Runs in the render image, which carries assimp.
 */
class ModelConverter
{
    /** Sniffed formats that reach the renderer only after conversion. */
    private const CONVERTS = ['fbx'];

    public function __construct(
        private readonly int $timeoutSeconds = 300,
        private readonly ContainerBroker $broker = new ContainerBroker,
    ) {}

    public static function needsConverting(string $format): bool
    {
        return in_array($format, self::CONVERTS, true);
    }

    /**
     * @param  string|null  $root  the folder the model was unpacked into, when it
     *                             came from an archive. An .obj keeps its materials
     *                             beside it, so mounting the model alone loses them.
     * @return array{status: string, path?: string, tool?: ?string, reason?: string, retryable?: bool}
     */
    public function toGlb(string $sourcePath, string $scratchDir, ?string $root = null): array
    {
        // The container may only write to /out, which is this directory.
        $out = $scratchDir.DIRECTORY_SEPARATOR.'out';
        $target = $out.DIRECTORY_SEPARATOR.'converted.glb';

        if (! is_dir($out) && ! mkdir($out, 0775, true) && ! is_dir($out)) {
            return ['status' => 'failed', 'reason' => 'The converter had nowhere to write.', 'retryable' => true];
        }

        @unlink($target);

        $answer = $this->broker->run(array_filter([
            'kind' => ContainerJob::CONVERT,
            'scratch' => ContainerBroker::scratchName($scratchDir),
            'source' => basename($root ?? $sourcePath),
            'bundle' => $root !== null,
            'entry' => $root === null ? null : ltrim(
                str_replace(DIRECTORY_SEPARATOR, '/', substr($sourcePath, strlen($root))),
                '/'
            ),
        ], fn ($value) => $value !== null), $this->timeoutSeconds + 120);

        // assimp reports a refused file on stdout with a non-zero exit; a
        // converter that crashed says nothing at all.
        $output = trim((string) ($answer['output'] ?? ''));
        $stderr = substr(trim((string) ($answer['error'] ?? '')), -600);
        $spoke = $output !== '' || $stderr !== '';

        Log::channel('render')->debug('Converter exited.', [
            'status' => $answer['status'],
            'exit_code' => $answer['exit'] ?? null,
            'stderr' => $stderr ?: null,
            'output' => substr($output, -600) ?: null,
        ]);

        if (! is_file($target) || filesize($target) === 0) {
            return [
                'status' => 'failed',
                'reason' => self::refusal($output),
                'retryable' => ! $spoke,
                'stderr' => $stderr,
            ];
        }

        return [
            'status' => 'ok',
            'path' => $target,
            // The build that did it, as the renderer records for the same reason.
            'tool' => $answer['image'] ?? null,
        ];
    }

    /** The tool's own words, which name the malformed part of the file. */
    private static function refusal(string $output): string
    {
        $said = preg_match('/^ERROR:\s*(.+)$/m', $output, $matches) === 1
            ? rtrim(trim($matches[1]), '.')
            : null;

        return $said === null
            ? 'That file could not be read for rendering.'
            : 'That file could not be read for rendering: '.substr($said, 0, 200).'.';
    }
}
