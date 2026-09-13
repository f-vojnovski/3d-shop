<?php

namespace Tests\Feature;

use App\Support\ContainerBroker;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * The broker is the only thing holding a Docker socket, so what it refuses is
 * the whole of the boundary. These send what a taken-over worker would send.
 */
class BrokerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            Redis::connection()->ping();
        } catch (\Throwable) {
            $this->markTestSkipped('Redis is not running.');
        }

        Redis::del(ContainerBroker::REQUESTS);
    }

    /** @return array{status: string, reason?: string} */
    private function ask(array $job): array
    {
        $broker = new ContainerBroker;
        $id = 'test-'.bin2hex(random_bytes(8));

        Redis::rpush(ContainerBroker::REQUESTS, json_encode(['id' => $id, 'job' => $job]));

        $this->artisan('broker:work', ['--once' => true])->assertSuccessful();

        $reply = Redis::blpop([ContainerBroker::replyKey($id)], 5);

        $this->assertNotEmpty($reply, 'The broker answered nothing.');

        return json_decode((string) $reply[1], true);
    }

    public function test_it_refuses_a_kind_of_work_nobody_offers(): void
    {
        $answer = $this->ask([
            'kind' => 'shell',
            'scratch' => 'render-scratch/1',
            'source' => 'model',
        ]);

        $this->assertSame('refused', $answer['status']);
        $this->assertStringContainsString('Unknown kind', $answer['reason']);
    }

    public function test_it_refuses_a_scratch_directory_that_climbs_out(): void
    {
        $answer = $this->ask([
            'kind' => 'render',
            'scratch' => 'render-scratch/../../../../etc',
            'source' => 'passwd',
        ]);

        $this->assertSame('refused', $answer['status']);
    }

    /** The point of the whole exercise: flags do not cross the list. */
    public function test_flags_in_a_request_are_ignored_rather_than_obeyed(): void
    {
        $answer = $this->ask([
            'kind' => 'render',
            'scratch' => 'render-scratch/does-not-exist',
            'source' => 'model',
            'privileged' => true,
            'network' => 'host',
            'image' => 'alpine',
            'entrypoint' => 'sh',
            'mounts' => ['/:/host'],
        ]);

        // It gets as far as looking for the directory, which is the proof: the
        // request was read as a job, never as a command.
        $this->assertNotSame('ran', $answer['status']);
        $this->assertSame('refused', $answer['status']);
        $this->assertStringContainsString('No such directory', $answer['reason']);
    }

    public function test_a_request_with_no_reply_address_is_dropped(): void
    {
        $channel = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('render')->andReturn($channel);

        Redis::rpush(ContainerBroker::REQUESTS, json_encode(['job' => ['kind' => 'render']]));

        $this->artisan('broker:work', ['--once' => true])->assertSuccessful();

        $channel->shouldHaveReceived('warning')->withArgs(
            fn ($message, $context = []) => is_string($message)
                && str_contains($message, 'no reply address')
        )->once();
    }
}
