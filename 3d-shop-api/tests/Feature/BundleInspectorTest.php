<?php

namespace Tests\Feature;

use App\Support\BundleInspector;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

/**
 * Hostile archives are built here rather than committed, and none of them is a
 * real bomb: a fabricated central directory exercises the ratio check exactly
 * as well at a few hundred bytes. Nothing in this file extracts anything.
 */
class BundleInspectorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/bundles');
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_an_ordinary_bundle_is_allowed(): void
    {
        $inspection = BundleInspector::of($this->zip([
            'car/car.obj' => "v 0 0 0\nf 1 1 1\n",
            'car/car.mtl' => "newmtl body\nmap_Kd textures/body.png\n",
            'car/textures/body.png' => 'PNG-BYTES',
        ]));

        $this->assertTrue($inspection->allowed());
        $this->assertNull($inspection->refusal);
        $this->assertSame(
            ['car/car.obj', 'car/car.mtl', 'car/textures/body.png'],
            $inspection->entries
        );
    }

    /** The folder shape is what sibling references depend on. */
    public function test_it_keeps_the_paths_rather_than_flattening_them(): void
    {
        $inspection = BundleInspector::of($this->zip([
            'a/b/c/deep.png' => 'PNG',
            'top.obj' => "v 0 0 0\n",
        ]));

        $this->assertSame(['a/b/c/deep.png', 'top.obj'], $inspection->entries);
    }

    /** @return list<array{0: string, 1: string}> */
    public static function hostilePaths(): array
    {
        return [
            'parent' => ['../escaped.txt', 'points outside'],
            'grandparent' => ['../../escaped.txt', 'points outside'],
            'mid path' => ['good/../../escaped.txt', 'points outside'],
            'absolute' => ['/etc/passwd', 'is absolute'],
            'drive letter' => ['C:/windows/system32/evil.dll', 'is absolute'],
            'backslash parent' => ['..\\escaped.txt', 'points outside'],
            'trailing dot' => ['good/texture.png.', 'ends in a dot or a space'],
            'trailing space' => ['good/texture.png ', 'ends in a dot or a space'],
            'reserved' => ['good/CON', 'reserved name'],
            'reserved with extension' => ['good/nul.png', 'reserved name'],
        ];
    }

    #[DataProvider('hostilePaths')]
    public function test_a_hostile_path_is_refused(string $name, string $because): void
    {
        $inspection = BundleInspector::of($this->zip([$name => 'x', 'ok/model.obj' => "v 0 0 0\n"]));

        $this->assertFalse($inspection->allowed(), "{$name} should have been refused");
        $this->assertStringContainsString($because, (string) $inspection->refusal);
        $this->assertSame([], $inspection->entries);
    }

    /**
     * extractTo drops one of these and reports success, so the seller loses a
     * texture and the render is wrong with nothing said.
     */
    public function test_two_names_differing_only_by_case_are_refused(): void
    {
        $inspection = BundleInspector::of($this->zip([
            't/Texture.png' => 'A',
            't/texture.png' => 'B',
        ]));

        $this->assertFalse($inspection->allowed());
        $this->assertStringContainsString('overwrite each other', (string) $inspection->refusal);
    }

    /** macOS folds unicode composition, so these are one file on disk. */
    public function test_two_names_differing_only_by_unicode_composition_are_refused(): void
    {
        // Different byte sequences, 11 and 12 bytes, that fold to one name.
        $inspection = BundleInspector::of($this->zip([
            "t/caf\u{00e9}.png" => 'A',
            "t/cafe\u{0301}.png" => 'B',
        ]));

        $this->assertFalse($inspection->allowed());
        $this->assertStringContainsString('overwrite each other', (string) $inspection->refusal);
    }

    /**
     * Real downloads routinely carry the original project as `source/x.zip`.
     * Refusing over one would turn away a large share of genuine bundles; not
     * opening it is the defence.
     */
    public function test_a_nested_archive_is_carried_rather_than_refused(): void
    {
        $inspection = BundleInspector::of($this->zip([
            'car/car.obj' => "v 0 0 0
",
            'source/project.zip' => 'PK-BYTES',
        ]));

        $this->assertTrue($inspection->allowed());
        $this->assertContains('source/project.zip', $inspection->entries);
    }

    public function test_an_overlong_path_is_refused(): void
    {
        $inspection = BundleInspector::of($this->zip([
            str_repeat('a', 200).'.png' => 'x',
        ]));

        $this->assertFalse($inspection->allowed());
        $this->assertStringContainsString('longer than', (string) $inspection->refusal);
    }

    public function test_an_empty_archive_is_refused(): void
    {
        $inspection = BundleInspector::of($this->zip([]));

        $this->assertFalse($inspection->allowed());
        $this->assertStringContainsString('empty', (string) $inspection->refusal);
    }

    public function test_something_that_is_not_a_zip_is_refused(): void
    {
        $path = $this->directory.'/not-a-zip.zip';
        File::put($path, 'v 0 0 0'."\n".'f 1 1 1');

        $inspection = BundleInspector::of($path);

        $this->assertFalse($inspection->allowed());
        $this->assertStringContainsString('could not be read as a zip', (string) $inspection->refusal);
    }

    public function test_too_many_entries_is_refused(): void
    {
        $files = [];

        for ($i = 0; $i < 2100; $i++) {
            $files["t/{$i}.png"] = 'x';
        }

        $inspection = BundleInspector::of($this->zip($files));

        $this->assertFalse($inspection->allowed());
        $this->assertStringContainsString('over the', (string) $inspection->refusal);
    }

    public function test_directory_entries_are_ignored_rather_than_listed(): void
    {
        $path = $this->directory.'/dirs.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addEmptyDir('car');
        $zip->addEmptyDir('car/textures');
        $zip->addFromString('car/car.obj', "v 0 0 0\n");
        $zip->close();

        $inspection = BundleInspector::of($path);

        $this->assertTrue($inspection->allowed());
        $this->assertSame(['car/car.obj'], $inspection->entries);
    }

    /**
     * A fabricated central directory, not a real bomb: the ratio check reads
     * declared sizes, so lying about them tests it at 200 bytes.
     */
    public function test_an_absurd_declared_ratio_is_refused(): void
    {
        $path = $this->zip(['big.bin' => str_repeat('x', 64)]);
        $this->declareSize($path, 80 * 1024 * 1024);

        $inspection = BundleInspector::of($path);

        $this->assertFalse($inspection->allowed());
        $this->assertStringContainsString('far more than its own size', (string) $inspection->refusal);
    }

    public function test_an_absurd_declared_total_is_refused(): void
    {
        $path = $this->zip(['big.bin' => str_repeat('x', 64)]);
        $this->declareSize($path, 3 * 1024 * 1024 * 1024);

        $inspection = BundleInspector::of($path);

        $this->assertFalse($inspection->allowed());
        $this->assertStringContainsString('over the', (string) $inspection->refusal);
    }

    /** @param  array<string, string>  $files */
    private function zip(array $files): string
    {
        $path = $this->directory.'/bundle-'.substr(md5(serialize(array_keys($files))), 0, 8).'.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        if ($files === []) {
            File::put($path, "PK".str_repeat(" ", 18));
        }

        return $path;
    }

    /** Rewrites the uncompressed size in the central directory, nothing else. */
    private function declareSize(string $path, int $size): void
    {
        $bytes = File::get($path);
        $at = strpos($bytes, "PK\x01\x02");

        $this->assertNotFalse($at, 'central directory not found');

        File::put($path, substr_replace($bytes, pack('V', $size), $at + 24, 4));
    }
}
