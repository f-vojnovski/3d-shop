<?php

namespace App\Support;

use ZipArchive;

/**
 * Writes the entries an inspection allowed, and nothing else.
 *
 * Streamed rather than handed to ZipArchive::extractTo, which flattens paths,
 * stops halfway on a bad entry and silently drops a case collision. Streaming
 * also gives the byte cap somewhere honest to live: a central directory can
 * declare ten bytes and produce four thousand, so the limit is counted as the
 * bytes arrive.
 */
class BundleExtractor
{
    public const MAX_BYTES = 1_500 * 1024 * 1024;

    /** Extensions that could be the thing being sold rather than a texture. */
    private const MODELS = ['obj', 'gltf', 'glb', 'stl', 'fbx'];

    private const CHUNK = 1 << 20;

    public function __construct(
        /** @var list<string> Relative paths written, in archive order. */
        public readonly array $files,
        public readonly int $bytes,
        public readonly ?string $failure,
        /** @var list<array{path: string, sha256: string, bytes: int}> */
        public readonly array $manifest = [],
    ) {}

    public static function extract(
        string $archivePath,
        BundleInspector $inspection,
        string $target,
        int $maxBytes = self::MAX_BYTES
    ): self {
        if (! $inspection->allowed()) {
            return new self([], 0, $inspection->refusal);
        }

        $zip = new ZipArchive();

        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            return new self([], 0, 'That file could not be read as a zip archive.');
        }

        $root = self::prepare($target);

        if ($root === null) {
            $zip->close();

            return new self([], 0, 'The bundle could not be unpacked.');
        }

        $written = [];
        $manifest = [];
        $total = 0;

        foreach ($inspection->entries as $name => $entry) {
            $destination = self::destinationFor($root, $entry);

            // Belt and braces. The inspection already refused every way a name
            // can climb, so this cannot fire; it is here because the day it
            // does, writing the file is the wrong thing to do.
            if ($destination === null) {
                $zip->close();
                self::remove($root);

                return new self([], 0, sprintf('A path in that archive points outside it ("%s").', $entry));
            }

            // Read by the name the archive holds, write by the validated one.
            $stream = $zip->getStream($name);

            if ($stream === false) {
                $zip->close();
                self::remove($root);

                // Carrying on would put a manifest row, and so a digest, behind
                // bytes nobody ever read.
                return new self([], 0, sprintf('That archive does not contain the file it lists as "%s".', $entry));
            }

            $copied = self::copyEntry($stream, $destination, $maxBytes - $total);

            if ($copied === null) {
                $zip->close();
                self::remove($root);

                return new self([], 0, sprintf(
                    'That archive unpacks to more than %s MB, so we stopped.',
                    number_format($maxBytes / 1048576)
                ));
            }

            $written[] = $entry;
            $manifest[] = [
                'path' => $entry,
                'sha256' => (string) hash_file('sha256', $destination),
                'bytes' => $copied,
            ];
            $total += $copied;
        }

        $zip->close();

        // Sorted, so the digest describes the contents rather than the order
        // the archive happened to list them in.
        usort($manifest, fn (array $a, array $b) => strcmp($a['path'], $b['path']));

        return new self($written, $total, null, $manifest);
    }

    public function succeeded(): bool
    {
        return $this->failure === null;
    }

    /**
     * One hash for the whole bundle. A single file's sha256 no longer says what
     * an image was drawn from, so the record names every file it was drawn with.
     */
    public function digest(): string
    {
        return hash('sha256', implode("\n", array_map(
            fn (array $entry) => $entry['path'].' '.$entry['sha256'],
            $this->manifest
        )));
    }

    /**
     * Which entries could be the model. Junk a packer adds is ignored rather
     * than refused: it is harmless to keep and only confuses this choice.
     *
     * @return list<string>
     */
    public function models(): array
    {
        $candidates = [];

        foreach ($this->files as $path) {
            if (self::isJunk($path) || self::isArchive($path)) {
                continue;
            }

            if (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::MODELS, true)) {
                $candidates[] = $path;
            }
        }

        return $candidates;
    }

    /** Archives are carried but never opened, so one is never the model. */
    private static function isArchive(string $path): bool
    {
        return in_array(
            strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'tgz'],
            true
        );
    }

    private static function isJunk(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if ($segment === '__MACOSX' || str_starts_with($segment, '.')) {
                return true;
            }
        }

        return in_array(strtolower(basename($path)), ['thumbs.db', 'desktop.ini'], true);
    }

    /**
     * @param  resource  $stream
     * @return int|null bytes written, or null once the budget is spent
     */
    private static function copyEntry($stream, string $destination, int $budget): ?int
    {
        if ($budget <= 0) {
            fclose($stream);

            return null;
        }

        @mkdir(dirname($destination), 0775, true);
        $out = fopen($destination, 'wb');

        if ($out === false) {
            fclose($stream);

            return null;
        }

        $written = 0;

        while (! feof($stream)) {
            $chunk = fread($stream, self::CHUNK);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $written += strlen($chunk);

            // Counted as it arrives, not as the archive claimed it would be.
            if ($written > $budget) {
                fclose($stream);
                fclose($out);
                @unlink($destination);

                return null;
            }

            fwrite($out, $chunk);
        }

        fclose($stream);
        fclose($out);

        return $written;
    }

    /** Segments are validated, so joining them cannot leave the root. */
    private static function destinationFor(string $root, string $entry): ?string
    {
        $segments = [];

        foreach (explode('/', $entry) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }

            $segments[] = $segment;
        }

        return $segments === [] ? null : $root.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments);
    }

    /** An empty directory of our own, so nothing already there is overwritten. */
    private static function prepare(string $target): ?string
    {
        self::remove($target);

        if (! @mkdir($target, 0775, true) && ! is_dir($target)) {
            return null;
        }

        $real = realpath($target);

        return $real === false ? null : $real;
    }

    private static function remove(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? self::remove($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
