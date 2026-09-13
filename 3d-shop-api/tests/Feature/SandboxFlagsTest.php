<?php

namespace Tests\Feature;

use App\Support\Sandbox;
use Tests\TestCase;

/**
 * Every other test substitutes the runners, so without these the container
 * can be made `--privileged --network=host` and the whole suite stays green.
 *
 * The exact list rather than its presence, so both directions fail: dropping a
 * flag, and keeping it while widening what it allows. ContainerCommandTest
 * checks that each kind of job actually carries them.
 */
class SandboxFlagsTest extends TestCase
{
    public function test_the_confinement_is_exactly_this(): void
    {
        $flags = Sandbox::confinement('2g', '2');

        $this->assertSame([
            '--network=none',
            '--cap-drop=ALL',
            '--security-opt=no-new-privileges',
            '--read-only',
            '--memory=2g',
            '--cpus=2',
            '--pids-limit=256',
            '--tmpfs', '/tmp:rw,nosuid,noexec,size=512m',
        ], array_values(array_filter(
            $flags,
            fn (string $flag) => ! str_starts_with($flag, '--cpuset-cpus=')
        )));
    }

    /**
     * A time budget alone leaves the container seeing every core, and
     * SwiftShader starts a render thread for each of them.
     */
    public function test_the_container_is_pinned_to_as_many_cores_as_it_may_use(): void
    {
        $pinned = array_values(array_filter(
            Sandbox::confinement('2g', '2'),
            fn (string $flag) => str_starts_with($flag, '--cpuset-cpus=')
        ));

        if ($pinned === []) {
            $this->markTestSkipped('This host does not report how many cores it has.');
        }

        $this->assertMatchesRegularExpression('/^--cpuset-cpus=\d+-\d+$/', $pinned[0]);

        [$first, $last] = explode('-', substr($pinned[0], strlen('--cpuset-cpus=')));

        $this->assertSame(2, (int) $last - (int) $first + 1, 'A 2-cpu job must be pinned to 2 cores.');
    }
}
