<?php

namespace App\Support;

/**
 * Translates a path inside this container into the path the Docker daemon
 * knows it by.
 *
 * The renderer runs as a sibling container, so the `-v` arguments are resolved
 * by the host's daemon and not by us. `/app/storage/...` means nothing to it.
 * Rather than asking the operator to supply the host path, the worker asks the
 * daemon what it mounted where — over the same socket it already needs to start
 * a container at all.
 */
class HostPaths
{
    private const SOCKET = '/var/run/docker.sock';

    /** @var array<string, string>|null container prefix => host prefix */
    private static ?array $mounts = null;

    public static function translate(string $path): string
    {
        $normalised = str_replace('\\', '/', $path);

        foreach (self::mounts() as $inside => $outside) {
            if ($normalised === $inside || str_starts_with($normalised, $inside.'/')) {
                return $outside.substr($normalised, strlen($inside));
            }
        }

        return $path;
    }

    /** Test seam, and the escape hatch when the daemon cannot be asked. */
    public static function using(?array $mounts): void
    {
        self::$mounts = $mounts;
    }

    /** @return array<string, string> */
    private static function mounts(): array
    {
        if (self::$mounts !== null) {
            return self::$mounts;
        }

        if (! is_file('/.dockerenv')) {
            return self::$mounts = [];
        }

        $configured = (string) env('RENDER_HOST_STORAGE', '');

        if ($configured !== '') {
            return self::$mounts = [rtrim(storage_path(), '/\\') => rtrim($configured, '/\\')];
        }

        return self::$mounts = self::fromDaemon();
    }

    /** @return array<string, string> */
    private static function fromDaemon(): array
    {
        $body = self::get('/containers/'.rawurlencode(gethostname() ?: '').'/json');

        if ($body === null) {
            return [];
        }

        $mounts = [];

        foreach ($body['Mounts'] ?? [] as $mount) {
            $inside = (string) ($mount['Destination'] ?? '');
            $outside = (string) ($mount['Source'] ?? '');

            if ($inside !== '' && $outside !== '') {
                $mounts[rtrim($inside, '/')] = rtrim($outside, '/');
            }
        }

        // Longest first, so a mount nested inside another one wins.
        uksort($mounts, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return $mounts;
    }

    /** @return array<string, mixed>|null */
    private static function get(string $path): ?array
    {
        $socket = @stream_socket_client('unix://'.self::SOCKET, $code, $message, 2);

        if ($socket === false) {
            return null;
        }

        fwrite($socket, "GET {$path} HTTP/1.1\r\nHost: docker\r\nConnection: close\r\n\r\n");
        $response = stream_get_contents($socket);
        fclose($socket);

        $split = strpos((string) $response, "\r\n\r\n");

        if ($split === false) {
            return null;
        }

        $decoded = json_decode(self::unchunk(substr((string) $response, $split + 4)), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** The daemon answers chunked, and there is no http client in this path. */
    private static function unchunk(string $body): string
    {
        if (preg_match('/^[0-9a-fA-F]+\r\n/', $body) !== 1) {
            return $body;
        }

        $out = '';

        while ($body !== '') {
            $end = strpos($body, "\r\n");

            if ($end === false) {
                break;
            }

            $size = hexdec(substr($body, 0, $end));

            if ($size === 0) {
                break;
            }

            $out .= substr($body, $end + 2, (int) $size);
            $body = substr($body, $end + 2 + (int) $size + 2);
        }

        return $out;
    }
}
