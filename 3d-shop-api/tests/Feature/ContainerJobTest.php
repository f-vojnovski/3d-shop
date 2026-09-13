<?php

namespace Tests\Feature;

use App\Support\ContainerJob;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The worker has no Docker socket, so this is where a taken-over worker is
 * stopped. Everything it may say is here; everything else is refused.
 */
class ContainerJobTest extends TestCase
{
    public function test_it_accepts_the_work_the_worker_really_does(): void
    {
        $job = ContainerJob::from([
            'kind' => 'render',
            'scratch' => 'render-scratch/44-gltf',
            'source' => 'model.glb',
        ]);

        $this->assertSame('render', $job->kind);
        $this->assertSame('render-scratch/44-gltf', $job->scratch);
        $this->assertSame('model.glb', $job->source);
        $this->assertFalse($job->bundle);
    }

    public function test_the_flags_come_from_the_kind_and_never_from_the_request(): void
    {
        $job = ContainerJob::from([
            'kind' => 'animate',
            'scratch' => 'clip-scratch/1186-0',
            'source' => 'model',
            'memory' => '64g',
            'cpus' => '64',
            'timeout' => 999999,
            'entrypoint' => 'sh',
        ]);

        $settings = $job->settings();

        $this->assertSame('3g', $settings['memory']);
        $this->assertSame('2', $settings['cpus']);
        $this->assertSame(600, $settings['timeout']);
        $this->assertSame('node', $settings['entrypoint']);
    }

    #[DataProvider('refusals')]
    public function test_it_refuses(string $why, array $request): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContainerJob::from($request);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function refusals(): array
    {
        $ok = ['kind' => 'render', 'scratch' => 'render-scratch/44-gltf', 'source' => 'model'];

        return [
            'a kind nobody offers' => ['kind', ['kind' => 'shell'] + $ok],
            'climbing out of the scratch root' => ['..', ['scratch' => 'render-scratch/../../../etc'] + $ok],
            'a root that is not a scratch root' => ['root', ['scratch' => 'etc/passwd'] + $ok],
            'an absolute scratch path' => ['absolute', ['scratch' => '/etc/passwd'] + $ok],
            'a deeper path than one name' => ['depth', ['scratch' => 'render-scratch/a/b'] + $ok],
            'a windows separator' => ['backslash', ['scratch' => 'render-scratch\..\..\windows'] + $ok],
            'a source that climbs' => ['source', ['source' => '../../../etc/passwd'] + $ok],
            'a source with a separator' => ['separator', ['source' => 'sub/model'] + $ok],
            'a source that is a dotfile trick' => ['dots', ['source' => '..'] + $ok],
            'an entry on something that is not a conversion' => ['entry', ['entry' => 'car.obj'] + $ok],
            'nothing at all' => ['empty', []],
            'a scratch that is not a string' => ['type', ['scratch' => ['render-scratch', 'x']] + $ok],
        ];
    }

    public function test_a_conversion_may_name_an_entry_inside_its_bundle(): void
    {
        $job = ContainerJob::from([
            'kind' => 'convert',
            'scratch' => 'convert/17',
            'source' => 'bundle',
            'bundle' => true,
            'entry' => 'car.obj',
        ]);

        $this->assertSame('car.obj', $job->entry);
        $this->assertTrue($job->bundle);
    }
}
