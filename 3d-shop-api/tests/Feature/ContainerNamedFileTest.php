<?php

namespace Tests\Feature;

use App\Support\Sandbox;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The container picks the names in its own result file, and those names reach a
 * path join. A compromised renderer that answers `../../../.env` must publish
 * nothing at all.
 */
class ContainerNamedFileTest extends TestCase
{
    private string $outbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outbox = storage_path('app/private/render-scratch/named-file-test');
        File::deleteDirectory($this->outbox);
        File::ensureDirectoryExists($this->outbox, 0775, true);
        File::put($this->outbox.DIRECTORY_SEPARATOR.'angle-0.png', 'a picture');

        // Something worth stealing, one level up from the outbox.
        File::put(dirname($this->outbox).DIRECTORY_SEPARATOR.'secret', 'not for you');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->outbox);
        File::delete(dirname($this->outbox).DIRECTORY_SEPARATOR.'secret');

        parent::tearDown();
    }

    public function test_a_file_the_container_really_produced_is_accepted(): void
    {
        $path = Sandbox::produced($this->outbox, 'angle-0.png');

        $this->assertNotNull($path);
        $this->assertSame('a picture', file_get_contents($path));
    }

    public static function namesAContainerMustNotGetAwayWith(): array
    {
        return [
            'climbs out' => ['../secret'],
            'climbs further' => ['../../../.env'],
            'absolute posix' => ['/etc/passwd'],
            'absolute windows' => ['C:\\Windows\\win.ini'],
            'backslash climb' => ['..\\secret'],
            'nested' => ['sub/dir/file.png'],
            'empty' => [''],
            'not a string' => [null],
            'array' => [['angle-0.png']],
            'dot segment' => ['./angle-0.png'],
            'null byte' => ["angle-0.png\0.txt"],
        ];
    }

    #[DataProvider('namesAContainerMustNotGetAwayWith')]
    public function test_a_name_that_is_not_a_plain_file_in_the_outbox_is_refused(mixed $named): void
    {
        $this->assertNull(
            Sandbox::produced($this->outbox, $named),
            'This name was accepted: '.var_export($named, true)
        );
    }

    /** A name that resolves out through a symlink the container planted. */
    public function test_a_symlink_out_of_the_outbox_is_refused(): void
    {
        $link = $this->outbox.DIRECTORY_SEPARATOR.'escape.png';

        if (! @symlink(dirname($this->outbox).DIRECTORY_SEPARATOR.'secret', $link)) {
            $this->markTestSkipped('This machine does not allow making symlinks.');
        }

        $this->assertNull(Sandbox::produced($this->outbox, 'escape.png'));
    }
}
