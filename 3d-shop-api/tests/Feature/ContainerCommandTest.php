<?php

namespace Tests\Feature;

use App\Support\ContainerCommand;
use App\Support\ContainerJob;
use App\Support\Sandbox;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * The worker writes into the scratch directory before the container reads it,
 * so it can leave something there that points elsewhere. Docker resolves a
 * mount source on the host, so a plain-looking name is not enough on its own.
 */
class ContainerCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('app/private/container-command-test');
        File::ensureDirectoryExists($this->root.'/render-scratch/44-gltf/out', 0775, true);
        File::put($this->root.'/render-scratch/44-gltf/model', 'bytes');
        File::put($this->root.'/render-scratch/44-gltf/job.json', '{}');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/render-scratch/44-gltf/*') as $path) {
            if (is_dir($path) && ! is_link($path)) {
                @rmdir($path);
            }
        }

        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_it_builds_the_one_command_the_job_is_allowed_to_be(): void
    {
        $command = ContainerCommand::for(
            ContainerJob::from([
                'kind' => 'render',
                'scratch' => 'render-scratch/44-gltf',
                'source' => 'model',
            ]),
            $this->root
        );

        $this->assertSame(['docker', 'run', '--rm'], array_slice($command, 0, 3));
        $this->assertContains('--network=none', $command);
        $this->assertContains('--read-only', $command);

        $mounts = self::mountsIn($command);

        $this->assertCount(3, $mounts);
        $this->assertStringEndsWith(':/in/model:ro', $mounts[0]);
        $this->assertStringEndsWith(':/out', $mounts[1]);
        $this->assertStringEndsWith(':/in/job.json:ro', $mounts[2]);
    }

    /** The escape this class exists for. */
    public function test_a_scratch_directory_that_points_elsewhere_is_refused(): void
    {
        $elsewhere = storage_path('app/private/container-command-elsewhere');
        File::ensureDirectoryExists($elsewhere, 0775, true);

        $link = $this->root.'/render-scratch/escaped';

        if (! self::junction($link, $elsewhere)) {
            $this->markTestSkipped('This machine will not make a junction.');
        }

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('leaves its directory');

            ContainerCommand::for(
                ContainerJob::from([
                    'kind' => 'render',
                    'scratch' => 'render-scratch/escaped',
                    'source' => 'model',
                ]),
                $this->root
            );
        } finally {
            self::unlink($link);
            File::deleteDirectory($elsewhere);
        }
    }

    /**
     * The dangerous link is not one pointing somewhere real, which realpath
     * resolves and the check above catches. It is one realpath cannot follow at
     * all: it answers false, and the same path then goes to Docker, which
     * resolves it again on the host where it may well point at something.
     */
    public function test_an_output_link_this_process_cannot_follow_is_refused(): void
    {
        $out = $this->root.'/render-scratch/44-gltf/out';
        @rmdir($out);

        if (! @symlink($this->root.'/render-scratch/44-gltf/not-a-real-place', $out)) {
            $this->markTestSkipped('This machine will not make a dangling symlink.');
        }

        try {
            $this->assertTrue(is_link($out), 'The fixture is meant to be a link.');
            $this->assertFalse(realpath($out), 'The fixture is meant to be a link realpath cannot follow.');

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('leaves its directory');

            $this->build('render');
        } finally {
            self::unlink($out);
        }
    }

    /** The same trick one level in: the model itself pointing out of the scratch. */
    public function test_a_source_that_points_outside_the_scratch_is_refused(): void
    {
        $elsewhere = storage_path('app/private/container-command-elsewhere');
        File::ensureDirectoryExists($elsewhere, 0775, true);

        $link = $this->root.'/render-scratch/44-gltf/model-link';

        if (! self::junction($link, $elsewhere)) {
            $this->markTestSkipped('This machine will not make a junction.');
        }

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('leaves its directory');

            ContainerCommand::for(
                ContainerJob::from([
                    'kind' => 'render',
                    'scratch' => 'render-scratch/44-gltf',
                    'source' => 'model-link',
                ]),
                $this->root
            );
        } finally {
            self::unlink($link);
            File::deleteDirectory($elsewhere);
        }
    }

    public function test_a_conversion_is_handed_its_arguments_and_no_job_file(): void
    {
        $command = ContainerCommand::for(
            ContainerJob::from([
                'kind' => 'convert',
                'scratch' => 'render-scratch/44-gltf',
                'source' => 'model',
            ]),
            $this->root
        );

        $this->assertContains('--entrypoint', $command);
        $this->assertContains('assimp', $command);
        $this->assertSame(['export', '/in/model', '/out/converted.glb'], array_slice($command, -3));

        foreach (self::mountsIn($command) as $mount) {
            $this->assertStringNotContainsString('/in/job.json', $mount);
        }
    }

    private static function onWindows(): bool
    {
        return str_starts_with(strtoupper(PHP_OS_FAMILY), 'WIN');
    }

    #[DataProvider('kinds')]
    public function test_every_kind_of_work_is_confined(string $kind): void
    {
        $command = $this->build($kind);

        foreach (Sandbox::confinement('2g', '2') as $flag) {
            if (str_starts_with($flag, '--memory=') || str_starts_with($flag, '--cpus=')) {
                continue;
            }

            $this->assertContains($flag, $command, "{$kind} dropped {$flag}");
        }
    }

    #[DataProvider('kinds')]
    public function test_no_kind_of_work_hands_over_the_host(string $kind): void
    {
        $command = $this->build($kind);

        foreach (['--privileged', '--network=host', '--cap-add=ALL', '--pid=host', '--ipc=host'] as $escape) {
            $this->assertNotContains($escape, $command, "{$kind} allows {$escape}");
        }
    }

    #[DataProvider('kinds')]
    public function test_only_the_output_is_writable(string $kind): void
    {
        $mounts = self::mountsIn($this->build($kind));

        $this->assertNotEmpty($mounts);

        foreach ($mounts as $mount) {
            if (str_contains($mount, ':/out')) {
                continue;
            }

            $this->assertStringEndsWith(':ro', $mount, "{$mount} is writable");
        }
    }

    /** Each entrypoint is its own: a proxy must not be able to reach the render path. */
    #[DataProvider('kinds')]
    public function test_each_kind_runs_its_own_entrypoint(string $kind): void
    {
        $command = $this->build($kind);
        $expected = [
            'render' => null,
            'proxy' => '/app/proxy.mjs',
            'animate' => '/app/animate.mjs',
            'convert' => 'assimp',
        ][$kind];

        if ($expected === null) {
            $this->assertNotContains('--entrypoint', $command);

            return;
        }

        $this->assertContains('--entrypoint', $command);
        $this->assertContains($expected, $command);

        if ($kind !== 'render') {
            $this->assertNotContains('/app/render.mjs', $command);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function kinds(): array
    {
        return [
            'render' => ['render'],
            'proxy' => ['proxy'],
            'animate' => ['animate'],
            'convert' => ['convert'],
        ];
    }

    /** @return list<string> */
    private function build(string $kind): array
    {
        return ContainerCommand::for(
            ContainerJob::from([
                'kind' => $kind,
                'scratch' => 'render-scratch/44-gltf',
                'source' => 'model',
            ]),
            $this->root
        );
    }

    private static function junction(string $link, string $target): bool
    {
        if (@symlink($target, $link)) {
            return true;
        }

        if (! self::onWindows()) {
            return false;
        }

        exec(sprintf('mklink /J %s %s 2>nul', escapeshellarg($link), escapeshellarg($target)));

        return is_dir($link);
    }

    private static function unlink(string $link): void
    {
        if (! file_exists($link) && ! is_link($link)) {
            return;
        }

        if (self::onWindows() && is_dir($link)) {
            exec(sprintf('rmdir %s 2>nul', escapeshellarg($link)));

            return;
        }

        @unlink($link);
    }

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    private static function mountsIn(array $command): array
    {
        $mounts = [];

        foreach ($command as $index => $argument) {
            if ($argument === '-v') {
                $mounts[] = $command[$index + 1];
            }
        }

        return $mounts;
    }
}
