<?php

namespace App\Support;

use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;

/**
 * Asks for a container to be run, from a process that cannot run one.
 *
 * The worker holds no Docker socket. It leaves a job on a list and waits for an
 * answer; the broker is the only thing on the machine that may talk to Docker,
 * and it only accepts what ContainerJob describes.
 *
 * Redis carries it because both sides already have it. Nothing new listens on a
 * port, so the broker gains no attack surface beyond the list itself - and what
 * arrives on that list is checked before it becomes a command.
 */
class ContainerBroker
{
    public const REQUESTS = 'containers:requests';

    /** Long enough to outlast the slowest job the broker will accept. */
    private const REPLY_TTL = 1200;

    /**
     * @param  array<string, mixed>  $job  as ContainerJob::from() reads it
     * @return array{status: string, exit?: int, output?: string, error?: string, reason?: string}
     */
    public function run(array $job, int $waitSeconds): array
    {
        $id = bin2hex(random_bytes(16));
        $reply = self::replyKey($id);

        Redis::rpush(self::REQUESTS, json_encode(['id' => $id, 'job' => $job]));
        Redis::expire(self::REQUESTS, self::REPLY_TTL);

        $answer = Redis::blpop([$reply], $waitSeconds);

        if ($answer === null || $answer === []) {
            return [
                'status' => 'failed',
                'reason' => 'The renderer did not answer in time.',
            ];
        }

        $decoded = json_decode((string) ($answer[1] ?? ''), true);

        return is_array($decoded) ? $decoded : [
            'status' => 'failed',
            'reason' => 'The renderer answered with nothing usable.',
        ];
    }

    /**
     * The scratch directory as the broker names it: a root and one name, with
     * the part that differs between the two containers taken off.
     */
    public static function scratchName(string $absolute): string
    {
        $private = str_replace('\\', '/', rtrim(storage_path('app/private'), '/\\')).'/';
        $normalised = str_replace('\\', '/', $absolute);

        if (! str_starts_with($normalised, $private)) {
            throw new InvalidArgumentException('That scratch directory is not under the private disk.');
        }

        return substr($normalised, strlen($private));
    }

    public static function replyKey(string $id): string
    {
        return 'containers:reply:'.$id;
    }

    public static function replyTtl(): int
    {
        return self::REPLY_TTL;
    }
}
