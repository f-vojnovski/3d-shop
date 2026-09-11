<?php

namespace Tests\Feature;

use App\Support\BundleExtractor;
use App\Support\BundleInspector;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * Nothing here is a real bomb. The byte cap is exercised by lowering the cap
 * and by an entry whose declared size is a lie, both at a few kilobytes.
 */
class BundleExtractorTest extends TestCase
{
    private string $directory;

    private string $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/extract');
        $this->target = $this->directory.'/unpacked';
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_writes_the_bundle_keeping_its_shape(): void
    {
        $archive = $this->zip([
            'car/car.obj' => "v 0 0 0\nf 1 1 1\n",
            'car/car.mtl' => "newmtl body\nmap_Kd textures/body.png\n",
            'car/textures/body.png' => 'PNG-BYTES',
        ]);

        $result = BundleExtractor::extract($archive, BundleInspector::of($archive), $this->target);

        $this->assertTrue($result->succeeded(), (string) $result->failure);
        $this->assertSame(
            ['car/car.obj', 'car/car.mtl', 'car/textures/body.png'],
            $result->files
        );

        // The folder shape is the whole point: references resolve through it.
        $this->assertFileExists($this->target.'/car/car.obj');
        $this->assertFileExists($this->target.'/car/textures/body.png');
        $this->assertSame('PNG-BYTES', File::get($this->target.'/car/textures/body.png'));
    }

    public function test_it_reports_what_it_wrote(): void
    {
        $archive = $this->zip(['a.obj' => str_repeat('x', 100), 'b.png' => str_repeat('y', 50)]);

        $result = BundleExtractor::extract($archive, BundleInspector::of($archive), $this->target);

        $this->assertSame(150, $result->bytes);
    }

    /**
     * The declared size is attacker-controlled: an entry can claim ten bytes
     * and stream four thousand, so the cap is counted as bytes arrive.
     */
    public function test_an_entry_that_lies_about_its_size_is_still_capped(): void
    {
        $archive = $this->zip(['big.bin' => str_repeat('x', 4096)]);
        $this->declareSize($archive, 10);

        $inspection = BundleInspector::of($archive);
        $this->assertTrue($inspection->allowed(), 'the index looks harmless, which is the point');

        $result = BundleExtractor::extract($archive, $inspection, $this->target, maxBytes: 1024);

        $this->assertFalse($result->succeeded());
        $this->assertStringContainsString('more than', (string) $result->failure);
    }

    public function test_nothing_is_left_behind_when_the_cap_is_hit(): void
    {
        $archive = $this->zip(['a.obj' => str_repeat('x', 4096)]);

        BundleExtractor::extract($archive, BundleInspector::of($archive), $this->target, maxBytes: 512);

        $this->assertDirectoryDoesNotExist($this->target);
    }

    public function test_a_budget_spent_on_earlier_entries_stops_the_later_ones(): void
    {
        $archive = $this->zip([
            'a.obj' => str_repeat('x', 600),
            'b.png' => str_repeat('y', 600),
        ]);

        $result = BundleExtractor::extract($archive, BundleInspector::of($archive), $this->target, maxBytes: 1000);

        $this->assertFalse($result->succeeded());
    }

    public function test_a_refused_inspection_writes_nothing(): void
    {
        $archive = $this->zip(['../escaped.txt' => 'x', 'ok.obj' => "v 0 0 0\n"]);
        $inspection = BundleInspector::of($archive);

        $this->assertFalse($inspection->allowed());

        $result = BundleExtractor::extract($archive, $inspection, $this->target);

        $this->assertFalse($result->succeeded());
        $this->assertDirectoryDoesNotExist($this->target);
    }

    /** A second bundle must not inherit the first one's files. */
    public function test_the_target_starts_empty(): void
    {
        File::ensureDirectoryExists($this->target.'/car');
        File::put($this->target.'/car/stale.png', 'from a previous bundle');

        $archive = $this->zip(['car/car.obj' => "v 0 0 0\n"]);
        BundleExtractor::extract($archive, BundleInspector::of($archive), $this->target);

        $this->assertFileExists($this->target.'/car/car.obj');
        $this->assertFileDoesNotExist($this->target.'/car/stale.png');
    }

    public function test_it_writes_only_inside_the_target(): void
    {
        $archive = $this->zip([
            'car/car.obj' => "v 0 0 0\n",
            'car/textures/body.png' => 'PNG',
        ]);

        BundleExtractor::extract($archive, BundleInspector::of($archive), $this->target);

        foreach (File::allFiles($this->directory) as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());

            // The archive itself sits beside the target, not inside it.
            if (str_ends_with($path, '.zip')) {
                continue;
            }

            $this->assertStringContainsString('/unpacked/', $path, "{$path} landed outside the target");
        }
    }

    /** @param  array<string, string>  $files */
    private function zip(array $files): string
    {
        $path = $this->directory.'/bundle-'.substr(md5(serialize($files)), 0, 8).'.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
    }

    private function declareSize(string $path, int $size): void
    {
        $bytes = File::get($path);
        $at = strpos($bytes, "PK\x01\x02");

        $this->assertNotFalse($at);

        File::put($path, substr_replace($bytes, pack('V', $size), $at + 24, 4));
    }
}
