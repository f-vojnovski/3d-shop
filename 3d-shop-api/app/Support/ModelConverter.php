<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Turns a format PHP cannot parse into a glb, so one measuring and rendering
 * path serves every format. Runs in the render image, which carries assimp.
 */
class ModelConverter
{
    /** One container start per process is enough; the image does not change. */
    private static ?string $identity = null;

    /** Sniffed formats that reach the renderer only after conversion. */
    private const CONVERTS = ['fbx'];

    public function __construct(
        private readonly int $timeoutSeconds = 300,
        private readonly string $memory = '2g',
        private readonly string $cpus = '2',
    ) {}

    public static function needsConverting(string $format): bool
    {
        return in_array($format, self::CONVERTS, true);
    }

    /**
     * @return array{status: string, path?: string, tool?: string, reason?: string, retryable?: bool}
     */
    public function toGlb(string $sourcePath, string $scratchDir): array
    {
        $target = $scratchDir.DIRECTORY_SEPARATOR.'converted.glb';
        @unlink($target);

        $process = new Process([
            'docker', 'run', '--rm',
            '--network=none', '--cap-drop=ALL',
            "--memory={$this->memory}", "--cpus={$this->cpus}",
            '--pids-limit=256', '--tmpfs', '/tmp:rw,size=512m',
            '--entrypoint', 'assimp',
            '-v', $this->hostPath($sourcePath).':/in/model:ro',
            '-v', $this->hostPath($scratchDir).':/out',
            RenderRunner::IMAGE,
            'export', '/in/model', '/out/converted.glb',
        ]);

        $process->setTimeout($this->timeoutSeconds + 60);
        $process->run();

        // assimp reports a refused file on stdout with a non-zero exit; a
        // converter that crashed says nothing at all.
        $output = trim($process->getOutput());
        $stderr = substr(trim($process->getErrorOutput()), -600);
        $spoke = $output !== '' || $stderr !== '';

        Log::channel('render')->debug('Converter exited.', [
            'exit_code' => $process->getExitCode(),
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
            'tool' => $this->identity(),
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

    /** Named in the provenance: the images come from what this produced. */
    private function identity(): string
    {
        if (self::$identity !== null) {
            return self::$identity;
        }

        $process = new Process([
            'docker', 'run', '--rm', '--network=none',
            '--entrypoint', 'assimp', RenderRunner::IMAGE, 'version',
        ]);
        $process->setTimeout(60);
        $process->run();

        $found = preg_match('/^Version\s+(.+)$/m', $process->getOutput(), $matches) === 1;

        return self::$identity = $found
            ? 'assimp '.substr(trim($matches[1]), 0, 80)
            : 'assimp unknown';
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
