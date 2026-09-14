<?php

namespace App\Support;

use App\Models\RenderRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use Throwable;

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
     * @param  array<string, mixed>  $about  what this work was for, for the record
     * @return array{status: string, exit?: int, output?: string, error?: string, reason?: string, image?: ?string}
     */
    public function run(array $job, int $waitSeconds, array $about = []): array
    {
        $id = bin2hex(random_bytes(16));
        $reply = self::replyKey($id);
        $asked = microtime(true);

        Redis::rpush(self::REQUESTS, json_encode(['id' => $id, 'job' => $job]));
        Redis::expire(self::REQUESTS, self::REPLY_TTL);

        $answer = Redis::blpop([$reply], $waitSeconds);

        if ($answer === null || $answer === []) {
            $result = ['status' => 'failed', 'reason' => 'The renderer did not answer in time.'];
        } else {
            $decoded = json_decode((string) ($answer[1] ?? ''), true);

            $result = is_array($decoded) ? $decoded : [
                'status' => 'failed',
                'reason' => 'The renderer answered with nothing usable.',
            ];
        }

        $this->record($job, $about, $asked, $result);

        return $result;
    }

    /**
     * Best effort: an accounting row that will not write must not lose a render
     * that already happened.
     *
     * @param  array<string, mixed>  $job
     * @param  array<string, mixed>  $about
     * @param  array<string, mixed>  $result
     */
    private function record(array $job, array $about, float $asked, array $result): void
    {
        try {
            $started = $result['started_at'] ?? null;
            $finished = $result['finished_at'] ?? null;
            $ran = $started !== null && $finished !== null ? $finished - $started : null;
            $cpus = (float) ($result['cpu_quota'] ?? 0);

            RenderRun::create([
                'kind' => (string) ($job['kind'] ?? 'unknown'),
                'scratch' => (string) ($job['scratch'] ?? ''),
                'product_file_id' => $about['product_file_id'] ?? null,
                'user_id' => $about['user_id'] ?? null,
                'triangles' => $about['triangles'] ?? null,
                'source_bytes' => $about['source_bytes'] ?? null,
                'asked_at' => CarbonImmutable::createFromTimestampUTC((int) $asked),
                'started_at' => $started === null ? null : CarbonImmutable::createFromTimestampUTC((int) $started),
                'finished_at' => $finished === null ? null : CarbonImmutable::createFromTimestampUTC((int) $finished),
                'queued_seconds' => $started === null ? null : round($started - $asked, 3),
                'run_seconds' => $ran === null ? null : round($ran, 3),
                // The unit a cloud charges for: what was reserved, for as long
                // as it was held, whether or not all of it was used.
                'vcpu_seconds' => $ran === null || $cpus <= 0 ? null : round($ran * $cpus, 3),
                'cpu_quota' => $result['cpu_quota'] ?? null,
                'memory' => $result['memory'] ?? null,
                'status' => (string) ($result['status'] ?? 'unknown'),
                'exit_code' => $result['exit'] ?? null,
                'failure_reason' => $result['reason'] ?? null,
            ]);
        } catch (Throwable $exception) {
            Log::channel('render')->warning('Could not record what a render cost.', [
                'message' => $exception->getMessage(),
            ]);
        }
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
