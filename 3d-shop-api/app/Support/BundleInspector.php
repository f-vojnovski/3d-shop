<?php

namespace App\Support;

use Normalizer;
use ZipArchive;

/**
 * Decides whether an archive is allowed anywhere near the render tier. Reads
 * the index only and writes nothing, so a refusal costs no disk and no CPU
 * beyond opening the file.
 *
 * It does not extract, and deliberately does not use ZipArchive::extractTo:
 * that flattens entry paths, which breaks the sibling references a bundle
 * exists for, stops halfway on one bad entry, and silently drops an entry that
 * collides with another by case while still reporting success.
 */
class BundleInspector
{
    /**
     * Composer's thresholds, hardened across millions of installs, rather than
     * numbers of our own. A first filter only: the central directory is
     * attacker-controlled and an entry declaring 10 bytes can stream 4096, so
     * the real cap is counted during extraction.
     */
    private const RATIO = 100;

    private const RATIO_FLOOR = 50 * 1024 * 1024;

    private const MAX_ENTRIES = 2_000;

    private const MAX_DECLARED_BYTES = 2 * 1024 * 1024 * 1024;

    private const MAX_PATH_LENGTH = 180;

    /** Reserved on Windows with or without an extension, in any case. */
    private const RESERVED = [
        'con', 'prn', 'aux', 'nul',
        'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9',
        'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9',
    ];

    /**
     * Never opened. Real downloads routinely carry the original project as
     * `source/whatever.zip`, so refusing the upload over one would turn away a
     * large share of genuine bundles; not recursing is the defence.
     */
    private const ARCHIVES = ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'tgz'];

    public function __construct(
        /** @var list<string> Validated relative paths, safe to join to a base. */
        public readonly array $entries,
        public readonly int $declaredBytes,
        public readonly int $archiveBytes,
        public readonly ?string $refusal,
    ) {}

    public static function of(string $absolutePath): self
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return self::refuse('That file could not be read as a zip archive.', 0);
        }

        $archiveBytes = filesize($absolutePath) ?: 0;
        $zip = new ZipArchive();

        if ($zip->open($absolutePath, ZipArchive::RDONLY) !== true) {
            return self::refuse('That file could not be read as a zip archive.', $archiveBytes);
        }

        $count = $zip->numFiles;

        if ($count === 0) {
            $zip->close();

            return self::refuse('That archive is empty.', $archiveBytes);
        }

        if ($count > self::MAX_ENTRIES) {
            $zip->close();

            return self::refuse(sprintf(
                'That archive holds %s files, over the %s limit.',
                number_format($count),
                number_format(self::MAX_ENTRIES)
            ), $archiveBytes);
        }

        $entries = [];
        $declared = 0;
        $seen = [];

        for ($index = 0; $index < $count; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false) {
                $zip->close();

                return self::refuse('One of the entries in that archive could not be read.', $archiveBytes);
            }

            $name = (string) $stat['name'];

            // Directory entries carry no bytes and nothing references them.
            if (str_ends_with($name, '/')) {
                continue;
            }

            if ($refusal = self::checkEntry($zip, $index, $stat, $name)) {
                $zip->close();

                return self::refuse($refusal, $archiveBytes);
            }

            $key = self::collisionKey($name);

            if (isset($seen[$key])) {
                $zip->close();

                return self::refuse(sprintf(
                    'Two files in that archive would overwrite each other: "%s" and "%s".',
                    $seen[$key],
                    $name
                ), $archiveBytes);
            }

            $seen[$key] = $name;
            $entries[] = self::relative($name);
            $declared += (int) $stat['size'];
        }

        $zip->close();

        if ($entries === []) {
            return self::refuse('That archive holds no files.', $archiveBytes);
        }

        if ($refusal = self::checkTotals($declared, $archiveBytes)) {
            return self::refuse($refusal, $archiveBytes);
        }

        return new self($entries, $declared, $archiveBytes, null);
    }

    public function allowed(): bool
    {
        return $this->refusal === null;
    }

    /** @param  array<string, mixed>  $stat */
    private static function checkEntry(ZipArchive $zip, int $index, array $stat, string $name): ?string
    {
        if ($name === '' || str_contains($name, "\0")) {
            return 'That archive holds a file with an unusable name.';
        }

        if (strlen($name) > self::MAX_PATH_LENGTH) {
            return sprintf('A path in that archive is longer than %d characters.', self::MAX_PATH_LENGTH);
        }

        if (((int) ($stat['encryption_method'] ?? 0)) !== 0) {
            return 'That archive is encrypted, so its contents cannot be read or rendered.';
        }

        if (self::isSymlink($zip, $index)) {
            return sprintf('That archive holds a link rather than a file ("%s").', $name);
        }

        return self::checkPath($name);
    }

    /**
     * Resolve, then verify containment, the same way the renderer confines the
     * one path it builds from a request. Checked against a notional base
     * because nothing is on disk yet.
     */
    private static function checkPath(string $name): ?string
    {
        $unix = str_replace('\\', '/', $name);

        if (str_starts_with($unix, '/') || preg_match('#^[A-Za-z]:#', $unix) === 1) {
            return sprintf('A path in that archive is absolute ("%s").', $name);
        }

        $segments = [];

        foreach (explode('/', $unix) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                return sprintf('A path in that archive points outside it ("%s").', $name);
            }

            if (rtrim($segment, ". \t") !== $segment) {
                return sprintf('A path in that archive ends in a dot or a space ("%s").', $name);
            }

            $bare = strtolower(explode('.', $segment)[0]);

            if (in_array($bare, self::RESERVED, true)) {
                return sprintf('A path in that archive uses the reserved name "%s".', $segment);
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            return 'That archive holds a file with an unusable name.';
        }

        $base = '/bundle';
        $resolved = $base.'/'.implode('/', $segments);

        if (! str_starts_with($resolved, $base.'/')) {
            return sprintf('A path in that archive points outside it ("%s").', $name);
        }

        return null;
    }

    private static function isSymlink(ZipArchive $zip, int $index): bool
    {
        $attributes = $zip->getExternalAttributesIndex($index, $opsys, $attr);

        if ($attributes !== true || $opsys !== ZipArchive::OPSYS_UNIX) {
            return false;
        }

        return (($attr >> 16) & 0xF000) === 0xA000;
    }

    private static function checkTotals(int $declared, int $archiveBytes): ?string
    {
        if ($declared > self::MAX_DECLARED_BYTES) {
            return sprintf(
                'That archive unpacks to about %s GB, over the %s GB limit.',
                number_format($declared / 1073741824, 1),
                number_format(self::MAX_DECLARED_BYTES / 1073741824, 1)
            );
        }

        if ($archiveBytes > 0
            && $declared > $archiveBytes * self::RATIO
            && $declared > self::RATIO_FLOOR) {
            return 'That archive unpacks to far more than its own size, so we will not open it.';
        }

        return null;
    }

    /**
     * Two names collide when a filesystem cannot tell them apart: Windows and
     * macOS fold case, and macOS also folds unicode composition, so "café" in
     * two encodings is one file on disk.
     */
    private static function collisionKey(string $name): string
    {
        $unix = strtolower(str_replace('\\', '/', $name));
        $normalised = Normalizer::normalize($unix, Normalizer::FORM_C);

        return $normalised === false ? $unix : $normalised;
    }

    private static function relative(string $name): string
    {
        return implode('/', array_values(array_filter(
            explode('/', str_replace('\\', '/', $name)),
            fn (string $segment) => $segment !== '' && $segment !== '.'
        )));
    }

    private static function refuse(string $why, int $archiveBytes): self
    {
        return new self([], 0, $archiveBytes, $why);
    }
}
