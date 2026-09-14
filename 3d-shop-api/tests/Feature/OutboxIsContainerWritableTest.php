<?php

namespace Tests\Feature;

use App\Support\RenderRunner;
use App\Support\Sandbox;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The container writes its result as a user the host does not share, with
 * CAP_DAC_OVERRIDE dropped, so the mode bits on this one directory are the
 * whole of its permission to write. A 0775 here is an EACCES nobody sees
 * until a render runs on Linux.
 */
class OutboxIsContainerWritableTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scratch = storage_path('app/private/outbox-test');
        File::deleteDirectory($this->scratch);
        File::ensureDirectoryExists($this->scratch, 0775, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);
        parent::tearDown();
    }

    public function test_the_outbox_is_writable_by_a_user_that_is_not_its_owner(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Mode bits decide nothing on Windows; this guards the Linux container.');
        }

        $out = Sandbox::outbox($this->scratch);

        $this->assertNotNull($out);
        $this->assertSame('0777', substr(sprintf('%o', fileperms((string) $out)), -4));
    }

    /** A umask of 022 turns a 0777 request into 0755, which is why chmod follows mkdir. */
    public function test_the_umask_does_not_get_a_say(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Mode bits decide nothing on Windows; this guards the Linux container.');
        }

        $was = umask(0027);

        try {
            $out = Sandbox::outbox($this->scratch);
            $this->assertSame('0777', substr(sprintf('%o', fileperms((string) $out)), -4));
        } finally {
            umask($was);
        }
    }

    public function test_an_existing_outbox_is_reused_rather_than_refused(): void
    {
        $first = Sandbox::outbox($this->scratch);
        File::put((string) $first.DIRECTORY_SEPARATOR.'result.json', '{}');

        $this->assertSame($first, Sandbox::outbox($this->scratch));
        $this->assertFileExists((string) $first.DIRECTORY_SEPARATOR.'result.json');
    }

    /** Better a refusal here than a container that starts and cannot write. */
    public function test_a_render_whose_outbox_cannot_be_made_does_not_start_a_container(): void
    {
        $blocked = $this->scratch.DIRECTORY_SEPARATOR.'blocked';
        File::put($blocked, 'a file where the scratch directory should be');

        $this->assertNull(Sandbox::outbox($blocked));

        $result = (new RenderRunner)->run([], $blocked.DIRECTORY_SEPARATOR.'model', $blocked);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('nowhere to write', $result['reason']);
    }
}
