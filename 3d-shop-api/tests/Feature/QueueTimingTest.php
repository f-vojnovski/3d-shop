<?php

namespace Tests\Feature;

use App\Jobs\HandlePaymentEvent;
use App\Payments\PaymentEvent;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use SplFileInfo;
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

    /** @return array<class-string, int> */
    private function jobTimeouts(): array
    {
        $found = [];

        foreach ((new Finder)->files()->in(app_path('Jobs'))->name('*.php') as $file) {
            /** @var SplFileInfo $file */
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
