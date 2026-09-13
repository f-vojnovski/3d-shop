<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * What the worker is allowed to ask a container to do.
 *
 * The worker has no Docker socket. It names a kind of work and one scratch
 * directory; everything else - the image, the entrypoint, the flags, every
 * mount - is chosen here from a fixed table. A worker that has been taken over
 * can ask for a render of a directory it already owns, and nothing else.
 *
 * This is the whole of the boundary, so a field added here is a security
 * change rather than a convenience.
 */
class ContainerJob
{
    public const RENDER = 'render';

    public const PROXY = 'proxy';

    public const ANIMATE = 'animate';

    public const CONVERT = 'convert';

    /**
     * Per kind: how it is started, what it may hold, and how long it may take.
     * Nothing in a request reaches this table except its key.
     *
     * @var array<string, array{entrypoint: ?string, arguments: list<string>, env: ?string, memory: string, cpus: string, timeout: int}>
     */
    private const KINDS = [
        self::RENDER => [
            'entrypoint' => null,
            'arguments' => [],
            'env' => 'RENDER_TIMEOUT',
            'memory' => '2g',
            'cpus' => '2',
            'timeout' => 180,
        ],
        self::PROXY => [
            'entrypoint' => 'node',
            'arguments' => ['/app/proxy.mjs'],
            'env' => 'PROXY_TIMEOUT',
            'memory' => '2g',
            'cpus' => '2',
            'timeout' => 180,
        ],
        self::ANIMATE => [
            'entrypoint' => 'node',
            'arguments' => ['/app/animate.mjs'],
            'env' => 'ANIMATE_TIMEOUT',
            'memory' => '3g',
            'cpus' => '2',
            'timeout' => 600,
        ],
        self::CONVERT => [
            'entrypoint' => 'assimp',
            'arguments' => [],
            'env' => null,
            'memory' => '2g',
            'cpus' => '2',
            'timeout' => 300,
        ],
    ];

    /** The scratch roots a job may live under, as PruneOrphanedFilesCommand sweeps them. */
    private const ROOTS = [
        'render-scratch', 'proxy-scratch', 'clip-scratch', 'custom-views',
        'custom-clips', 'bundle-read', 'convert', 'render-verify', 'remeasure',
    ];

    private function __construct(
        public readonly string $kind,
        public readonly string $scratch,
        public readonly string $source,
        public readonly bool $bundle,
        public readonly ?string $entry,
    ) {}

    /**
     * @param  array<string, mixed>  $request
     *
     * @throws InvalidArgumentException on anything this does not recognise
     */
    public static function from(array $request): self
    {
        $kind = self::string($request, 'kind');

        if (! isset(self::KINDS[$kind])) {
            throw new InvalidArgumentException('Unknown kind of work.');
        }

        $entry = ($request['entry'] ?? null) === null ? null : self::relative(self::string($request, 'entry'));

        if ($kind !== self::CONVERT && $entry !== null) {
            throw new InvalidArgumentException('Only a conversion names an entry.');
        }

        return new self(
            kind: $kind,
            scratch: self::scratch(self::string($request, 'scratch')),
            source: self::name(self::string($request, 'source'), 'source'),
            bundle: (bool) ($request['bundle'] ?? false),
            entry: $entry,
        );
    }

    /** @return array{entrypoint: ?string, arguments: list<string>, env: ?string, memory: string, cpus: string, timeout: int} */
    public function settings(): array
    {
        return self::KINDS[$this->kind];
    }

    /**
     * `<root>/<name>` and nothing else: one known root, one plain segment. No
     * separators survive, so there is no traversal left to resolve.
     */
    private static function scratch(string $value): string
    {
        $parts = explode('/', str_replace('\\', '/', $value));

        if (count($parts) !== 2) {
            throw new InvalidArgumentException('A scratch directory is a known root and one name.');
        }

        [$root, $name] = $parts;

        if (! in_array($root, self::ROOTS, true)) {
            throw new InvalidArgumentException('That is not a scratch root.');
        }

        return $root.'/'.self::name($name, 'scratch');
    }

    /**
     * A model inside a bundle may sit in a folder, so this one keeps its
     * separators - but every segment is still a plain name, so there is nothing
     * left that climbs.
     */
    private static function relative(string $value): string
    {
        $unix = str_replace('\\', '/', $value);

        if (str_starts_with($unix, '/') || preg_match('#^[A-Za-z]:#', $unix) === 1) {
            throw new InvalidArgumentException('The entry is absolute.');
        }

        $segments = array_filter(explode('/', $unix), fn (string $part) => $part !== '' && $part !== '.');

        if ($segments === []) {
            throw new InvalidArgumentException('The entry names nothing.');
        }

        foreach ($segments as $segment) {
            self::name($segment, 'entry');
        }

        return implode('/', $segments);
    }

    /** A single path segment, with nothing in it that means anything to a filesystem. */
    private static function name(string $value, string $field): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $value) !== 1) {
            throw new InvalidArgumentException("The {$field} is not a plain name.");
        }

        if (str_contains($value, '..')) {
            throw new InvalidArgumentException("The {$field} points outside itself.");
        }

        return $value;
    }

    /** @param  array<string, mixed>  $request */
    private static function string(array $request, string $field): string
    {
        $value = $request[$field] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("The {$field} is missing.");
        }

        return $value;
    }
}
