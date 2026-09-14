<?php

namespace Tests\Feature;

use App\Support\BundleExtractor;
use App\Support\BundleInspector;
use App\Support\RenderInput;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * An archive can hold more than one model. Pick differently on each side and a
 * listing shows one mesh beside another's specifications, with every hash valid.
 */
class BundleEntryAgreementTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = storage_path('app/private/bundle-read/entry-agreement-test');
        File::deleteDirectory($this->scratch);
        File::ensureDirectoryExists($this->scratch, 0775, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    /**
     * The decoy is written into the archive first, so anything picking by
     * archive order picks it.
     */
    public function test_the_drawn_file_is_the_one_the_listing_claims(): void
    {
        $archive = $this->archiveWith([
            'decoy.stl' => "solid decoy\nendsolid decoy\n",
            'real.obj' => "v 0 0 0\nv 1 0 0\nv 0 1 0\nf 1 2 3\n",
        ]);

        $unpacked = RenderInput::unpack($archive, $this->scratch, 'obj');

        $this->assertIsArray($unpacked, 'The archive was refused: '.(is_string($unpacked) ? $unpacked : ''));
        $this->assertSame('real.obj', $unpacked['entry'], 'A listing selling .obj was about to be drawn from the .stl.');
    }

    public function test_an_archive_without_the_claimed_format_is_refused(): void
    {
        $archive = $this->archiveWith([
            'decoy.stl' => "solid decoy\nendsolid decoy\n",
        ]);

        $this->assertSame(
            'That archive holds no .obj file to draw.',
            RenderInput::unpack($archive, $this->scratch, 'obj')
        );
    }

    /** Measuring and drawing ask one function, so they cannot drift apart. */
    public function test_both_sides_ask_the_same_question(): void
    {
        $archive = $this->archiveWith([
            'decoy.stl' => "solid decoy\nendsolid decoy\n",
            'real.obj' => "v 0 0 0\nf 1 1 1\n",
        ]);

        $directory = $this->scratch.DIRECTORY_SEPARATOR.'read';
        $extracted = BundleExtractor::extract($archive, BundleInspector::of($archive), $directory);

        $this->assertSame(['real.obj'], $extracted->modelsFor('obj'));
        $this->assertSame(['decoy.stl'], $extracted->modelsFor('stl'));

        // And the ordering that used to decide it, to show they differ.
        $this->assertSame('decoy.stl', $extracted->models()[0]);
    }

    /** @param  array<string, string>  $entries */
    private function archiveWith(array $entries): string
    {
        $path = $this->scratch.DIRECTORY_SEPARATOR.'bundle.zip';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
    }
}
