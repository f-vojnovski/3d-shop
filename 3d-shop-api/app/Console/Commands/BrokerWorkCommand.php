<?php

namespace App\Console\Commands;

use App\Support\ContainerBroker;
use App\Support\ContainerCommand;
use App\Support\ContainerJob;
use App\Support\RenderRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The one process on the machine allowed to talk to Docker.
 *
 * It takes a description of work, never a command. A worker that has been taken
 * over can ask for a render of a directory it already owns; it cannot choose an
 * image, a flag, a mount or an entrypoint, because none of those cross the list.
 *
 * Run this where the socket is. Nothing else should have it.
 */
class BrokerWorkCommand extends Command
{
    protected $signature = 'broker:work {--once : Take a single job and stop}';

    protected $description = 'Run containers on behalf of workers that hold no Docker socket';

    /** How long to wait on the list before looking at whether to stop. */
    private const POLL_SECONDS = 5;

    private bool $stopping = false;

    public function handle(): int
    {
        $log = Log::channel('render');

        // A job is off the list before it runs, so the one in hand must finish or it is lost.
        if (extension_loaded('pcntl')) {
            $this->trap([SIGTERM, SIGINT], function () use ($log): void {
                $this->stopping = true;
                $log->info('Broker asked to stop; finishing the job in hand.');
            });
        }

        $this->info('Broker waiting for work.');

        do {
            $message = Redis::blpop([ContainerBroker::REQUESTS], self::POLL_SECONDS);

            if ($message === null || $message === []) {
                continue;
            }

            $this->take((string) ($message[1] ?? ''), $log);
        } while (! $this->option('once') && ! $this->stopping);

        return self::SUCCESS;
    }

    private function take(string $raw, $log): void
    {
        $decoded = json_decode($raw, true);
        $id = is_array($decoded) ? ($decoded['id'] ?? null) : null;

        if (! is_string($id) || $id === '') {
            $log->warning('Broker discarded a request with no reply address.');

            return;
        }

        $result = $this->carryOut(is_array($decoded['job'] ?? null) ? $decoded['job'] : [], $log);

        Redis::rpush(ContainerBroker::replyKey($id), json_encode($result));
        Redis::expire(ContainerBroker::replyKey($id), ContainerBroker::replyTtl());
    }

    /**
     * @param  array{memory: string, cpus: string, timeout: int}  $settings
     * @return array{started_at: float, finished_at: float, cpu_quota: string, memory: string, image: ?string}
     */
    private static function spent(float $began, array $settings, ?string $image): array
    {
        return [
            'started_at' => $began,
            'finished_at' => microtime(true),
            'cpu_quota' => $settings['cpus'],
            'memory' => $settings['memory'],
            'image' => $image,
        ];
    }

    private function imageDigest(): ?string
    {
        $inspect = new Process(['docker', 'image', 'inspect', '--format', '{{.Id}}', RenderRunner::IMAGE]);
        $inspect->setTimeout(20);

        try {
            $inspect->run();
        } catch (Throwable) {
            return null;
        }

        $id = trim($inspect->getOutput());

        return preg_match('/^sha256:[0-9a-f]{64}$/', $id) === 1 ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array{status: string, exit?: int, output?: string, error?: string, reason?: string}
     */
    private function carryOut(array $job, $log): array
    {
        try {
            $described = ContainerJob::from($job);
            $command = ContainerCommand::for($described, storage_path('app/private'));
        } catch (InvalidArgumentException|RuntimeException $refusal) {
            // Worth shouting about: nothing this project sends should land here,
            // so one of these is either a bug or someone trying it on.
            $log->error('Broker refused a request.', [
                'reason' => $refusal->getMessage(),
                'job' => $job,
            ]);

            return ['status' => 'refused', 'reason' => $refusal->getMessage()];
        }

        $settings = $described->settings();
        $image = $this->imageDigest();
        $process = new Process($command);
        $process->setTimeout($settings['timeout'] + 60);

        // Reported back because only this side knows when the container really
        // began: the gap before it is queue, and the gap after it is work.
        $began = microtime(true);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return ['status' => 'failed', 'reason' => 'The container ran past its time.'] + self::spent($began, $settings, $image);
        } catch (Throwable $exception) {
            $log->error('Broker could not start a container.', ['message' => $exception->getMessage()]);

            return ['status' => 'failed', 'reason' => 'The container would not start.'] + self::spent($began, $settings, $image);
        }

        return [
            'status' => 'ran',
            'exit' => (int) $process->getExitCode(),
            'output' => $process->getOutput(),
            'error' => substr($process->getErrorOutput(), -2000),
        ] + self::spent($began, $settings, $image);
    }
}
