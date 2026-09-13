<?php

namespace Tests\Feature;

use App\Models\RenderRun;
use App\Support\ContainerBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * What a render cost, kept as a row rather than left in a log.
 *
 * The two numbers that matter are kept apart: waiting for a worker and doing
 * the work have opposite fixes, and one figure cannot tell them apart.
 */
class RenderRunsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            Redis::connection()->ping();
        } catch (\Throwable) {
            $this->markTestSkipped('Redis is not running.');
        }
    }

    public function test_a_run_is_recorded_with_the_wait_and_the_work_kept_apart(): void
    {
        $this->fakeBrokerReply([
            'status' => 'ran',
            'exit' => 0,
            'started_at' => microtime(true) + 2.0,
            'finished_at' => microtime(true) + 6.0,
            'cpu_quota' => '2',
            'memory' => '2g',
        ]);

        (new ContainerBroker)->run(
            ['kind' => 'render', 'scratch' => 'render-scratch/7', 'source' => 'model'],
            5,
            ['user_id' => null, 'triangles' => 1499072, 'source_bytes' => 42977928]
        );

        $run = RenderRun::firstOrFail();

        $this->assertSame('render', $run->kind);
        $this->assertSame('render-scratch/7', $run->scratch);
        $this->assertSame('ran', $run->status);
        $this->assertSame(1499072, (int) $run->triangles);
        $this->assertEqualsWithDelta(4.0, $run->run_seconds, 0.5);
        $this->assertEqualsWithDelta(2.0, $run->queued_seconds, 0.5);

        // The unit a cloud bills in: what was held, for as long as it was held.
        $this->assertEqualsWithDelta(8.0, $run->vcpu_seconds, 1.0);
    }

    public function test_a_refusal_is_recorded_too(): void
    {
        $this->fakeBrokerReply(['status' => 'refused', 'reason' => 'Unknown kind of work.']);

        (new ContainerBroker)->run(['kind' => 'shell', 'scratch' => 'render-scratch/7', 'source' => 'model'], 5);

        $run = RenderRun::firstOrFail();

        $this->assertSame('refused', $run->status);
        $this->assertSame('Unknown kind of work.', $run->failure_reason);
        $this->assertNull($run->run_seconds);
    }

    /** An accounting row that will not write must not lose a render that happened. */
    public function test_a_render_still_succeeds_when_the_record_cannot_be_written(): void
    {
        Schema::drop('render_runs');

        $this->fakeBrokerReply(['status' => 'ran', 'exit' => 0]);

        $answer = (new ContainerBroker)->run(
            ['kind' => 'render', 'scratch' => 'render-scratch/7', 'source' => 'model'],
            5
        );

        $this->assertSame('ran', $answer['status']);
    }

    private function fakeBrokerReply(array $reply): void
    {
        Redis::shouldReceive('rpush')->andReturnTrue();
        Redis::shouldReceive('expire')->andReturnTrue();
        Redis::shouldReceive('blpop')->andReturn(['key', json_encode($reply)]);
    }
}
