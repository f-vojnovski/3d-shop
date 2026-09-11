<?php

namespace Tests\Feature;

use App\Support\ModelConverter;
use App\Support\RenderRunner;
use App\Support\Sandbox;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every other test substitutes `RenderRunner`, so without these the container
 * can be made `--privileged --network=host` and the whole suite stays green.
 *
 * They assert the exact list rather than its presence, so both directions
 * fail: dropping a flag, and keeping it while widening what it allows.
 */
class SandboxFlagsTest extends TestCase
{
    public function test_the_confinement_is_exactly_this(): void
    {
        $this->assertSame([
            '--network=none',
            '--cap-drop=ALL',
            '--memory=2g',
            '--cpus=2',
            '--pids-limit=256',
            '--tmpfs', '/tmp:rw,size=512m',
        ], Sandbox::confinement('2g', '2'));
    }

    #[DataProvider('commands')]
    public function test_every_container_that_opens_an_uploaded_file_is_confined(string $which): void
    {
        $command = self::commandFor($which);

        foreach (Sandbox::confinement('2g', '2') as $flag) {
            $this->assertContains($flag, $command, "{$which} dropped {$flag}");
        }
    }

    #[DataProvider('commands')]
    public function test_nothing_hands_the_container_the_host(string $which): void
    {
        $command = self::commandFor($which);

        foreach (['--privileged', '--network=host', '--cap-add=ALL', '--pid=host', '--ipc=host'] as $escape) {
            $this->assertNotContains($escape, $command, "{$which} allows {$escape}");
        }
    }

    #[DataProvider('commands')]
    public function test_the_uploaded_file_is_mounted_read_only(string $which): void
    {
        $mounts = self::mountsIn(self::commandFor($which));

        $this->assertNotEmpty($mounts);

        foreach ($mounts as $mount) {
            if (str_contains($mount, ':/out')) {
                continue;
            }

            $this->assertStringEndsWith(':ro', $mount, "{$mount} is writable");
        }
    }

    public function test_a_bundle_is_mounted_as_a_folder_and_still_read_only(): void
    {
        $command = (new RenderRunner)->commandFor('/scratch/model', '/scratch/job.json', '/scratch/out', '/scratch/bundle');

        $this->assertContains('/scratch/bundle:/in/bundle:ro', self::mountsIn($command));
    }

    /** @return array<string, array{0: string}> */
    public static function commands(): array
    {
        return [
            'renderer' => ['renderer'],
            'converter' => ['converter'],
        ];
    }

    /** @return list<string> */
    private static function commandFor(string $which): array
    {
        return $which === 'renderer'
            ? (new RenderRunner)->commandFor('/scratch/model', '/scratch/job.json', '/scratch/out')
            : (new ModelConverter)->commandFor('/scratch/model', '/scratch');
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
