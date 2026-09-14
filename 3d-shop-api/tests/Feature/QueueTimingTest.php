<?php

namespace Tests\Feature;

use App\Jobs\HandlePaymentEvent;
use App\Payments\PaymentEvent;
use App\Support\AnimateRunner;
use App\Support\ContainerJob;
use App\Support\ModelConverter;
use App\Support\ProxyRunner;
use App\Support\RenderRunner;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Redis re-delivers a job it has not heard about for `retry_after` seconds. If
 * a job is allowed to run longer than that, a second worker picks up work the
 * first is still doing: same scratch directory, same rows.
 *
 * Invisible with one worker and certain with two.
 */
class QueueTimingTest extends TestCase
{
    public function test_redis_waits_longer_than_the_longest_job_can_run(): void
    {
        $longest = 0;
        $slowest = null;

        foreach ($this->jobTimeouts() as $job => $timeout) {
            if ($timeout > $longest) {
                [$longest, $slowest] = [$timeout, $job];
            }
        }

        $this->assertGreaterThan(
            $longest,
            (int) config('queue.connections.redis.retry_after'),
            "retry_after must exceed every job timeout; {$slowest} is allowed {$longest}s."
        );
    }

    /**
     * A buyer who has paid should not wait behind a render.
     */
    public function test_money_does_not_queue_behind_renders(): void
    {
        Queue::fake();

        HandlePaymentEvent::dispatch(new PaymentEvent(
            id: 'evt_1',
            kind: PaymentEvent::PAID,
            providerType: 'checkout.session.completed',
        ));

        Queue::assertPushedOn(HandlePaymentEvent::QUEUE, HandlePaymentEvent::class);
        $this->assertNotSame('default', HandlePaymentEvent::QUEUE);
    }

    /** A wait longer than the socket allows throws while the container is still working. */
    public function test_the_redis_socket_outlasts_the_longest_broker_wait(): void
    {
        $cap = config('database.redis.default.read_write_timeout');

        $this->assertNotNull($cap, 'Unset leaves predis on the 60s default_socket_timeout.');

        // 0 means no cap, which no wait can outlast.
        if ((int) $cap !== 0) {
            $this->assertGreaterThan($this->longestBrokerWait(), (int) $cap);
        }
    }

    public function test_the_broker_is_given_time_to_finish_a_container_before_it_is_killed(): void
    {
        $broker = $this->composeService('broker');

        preg_match('/stop_grace_period: (\d+)s/', $broker, $matches);

        $this->assertNotEmpty($matches, 'Without one, Docker kills the broker after 10s.');
        $this->assertGreaterThan($this->longestContainerTimeout(), (int) $matches[1]);
    }

    private function composeService(string $name): string
    {
        $chunks = preg_split(
            '/^  (?=\S)/m',
            (string) file_get_contents(base_path('../docker-compose.yml'))
        );

        foreach ($chunks as $chunk) {
            if (str_starts_with($chunk, $name.':')) {
                return $chunk;
            }
        }

        $this->fail("No {$name} service in docker-compose.yml.");
    }

    private function longestContainerTimeout(): int
    {
        $kinds = (new ReflectionClass(ContainerJob::class))->getConstant('KINDS');

        $this->assertNotEmpty($kinds, 'No container kinds were found to check.');

        return max(array_column($kinds, 'timeout'));
    }

    /** The 120 is the margin each runner adds on top of its container timeout. */
    private function longestBrokerWait(): int
    {
        $runners = [RenderRunner::class, ProxyRunner::class, AnimateRunner::class, ModelConverter::class];
        $longest = 0;

        foreach ($runners as $runner) {
            foreach ((new ReflectionClass($runner))->getConstructor()->getParameters() as $parameter) {
                if ($parameter->getName() === 'timeoutSeconds') {
                    $longest = max($longest, (int) $parameter->getDefaultValue());
                }
            }
        }

        $this->assertGreaterThan(0, $longest, 'No runner timeout was found to check.');

        return $longest + 120;
    }

    /** @return array<class-string, int> */
    private function jobTimeouts(): array
    {
        $found = [];

        foreach ((new Finder)->files()->in(app_path('Jobs'))->name('*.php') as $file) {
            $class = sprintf('App\Jobs\%s', $file->getBasename('.php'));
            $properties = (new ReflectionClass($class))->getDefaultProperties();

            if (isset($properties['timeout'])) {
                $found[$class] = (int) $properties['timeout'];
            }
        }

        $this->assertNotEmpty($found, 'No jobs with a timeout were found to check.');

        return $found;
    }
}
