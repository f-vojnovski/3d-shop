<?php

namespace App\Console\Commands;

use App\Support\ContainerBroker;
use App\Support\ContainerCommand;
use App\Support\ContainerJob;
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

    public function handle(): int
    {
        $log = Log::channel('render');
        $this->info('Broker waiting for work.');

        do {
            $message = Redis::blpop([ContainerBroker::REQUESTS], self::POLL_SECONDS);

            if ($message === null || $message === []) {
                continue;
            }

            $this->take((string) ($message[1] ?? ''), $log);
        } while (! $this->option('once'));

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

        $timeout = $described->settings()['timeout'];
        $process = new Process($command);
        $process->setTimeout($timeout + 60);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return ['status' => 'failed', 'reason' => 'The container ran past its time.'];
        } catch (Throwable $exception) {
            $log->error('Broker could not start a container.', ['message' => $exception->getMessage()]);

            return ['status' => 'failed', 'reason' => 'The container would not start.'];
        }

        return [
            'status' => 'ran',
            'exit' => (int) $process->getExitCode(),
            'output' => $process->getOutput(),
            'error' => substr($process->getErrorOutput(), -2000),
        ];
    }
}
