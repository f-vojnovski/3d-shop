<?php

namespace Tests\Feature;

use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Redis re-delivers a job it has not heard about for `retry_after` seconds. If
 * a job is allowed to run longer than that, a second worker picks up work the
 * first is still doing: same scratch directory, same rows.
 *
 * This is invisible with one worker and certain with two. It has already gone
 * wrong once - `retry_after` named a 600s job in a comment while 900s jobs were
 * added beside it - so the rule is asserted rather than remembered.
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
