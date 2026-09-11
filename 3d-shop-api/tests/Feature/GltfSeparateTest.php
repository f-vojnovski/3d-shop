<?php

namespace Tests\Feature;

use App\Support\ModelUpload;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * A glTF ships either as one `.glb` or as a `.gltf` naming its buffer and its
 * images beside it. Only the second shape has siblings to lose.
 */
class GltfSeparateTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/gltf');
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        File::deleteDirectory(storage_path('app/private/bundle-read'));

        parent::tearDown();
    }

    public function test_it_measures_a_texture_kept_beside_the_model(): void
    {
        $upload = $this->read([
            'box/box.gltf' => $this->gltf(['uri' => 'textures/logo.png']),
            'box/textures/logo.png' => $this->png(256, 128),
        ]);

        $this->assertTrue($upload->accepted(), (string) $upload->refusal);
        $this->assertSame([['width' => 256, 'height' => 128]], $upload->facts->textures);
    }

    public function test_it_measures_an_image_written_into_the_json(): void
    {
        $data = 'data:image/png;base64,'.base64_encode($this->png(64, 32));

        $upload = $this->read(['box/box.gltf' => $this->gltf(['uri' => $data])]);

        $this->assertSame([['width' => 64, 'height' => 32]], $upload->facts->textures);
    }

    public function test_a_texture_outside_the_bundle_is_not_read(): void
    {
        // Really there, so it is the containment rule that refuses it and not
        // the file simply being absent.
        $outside = storage_path('app/private/secret.png');
        File::ensureDirectoryExists(dirname($outside));
        File::put($outside, $this->png(1024, 1024));

        try {
            $upload = $this->read([
                'box/box.gltf' => $this->gltf(['uri' => '../../../secret.png']),
                'box/textures/logo.png' => $this->png(8, 8),
            ]);

            $this->assertTrue($upload->accepted(), (string) $upload->refusal);
            $this->assertSame([], $upload->facts->textures);
        } finally {
            File::delete($outside);
        }
    }

    public function test_a_uri_that_names_nothing_present_is_skipped(): void
    {
        $upload = $this->read(['box/box.gltf' => $this->gltf(['uri' => 'textures/absent.png'])]);

        $this->assertSame([], $upload->facts->textures);
    }

    /** @param  array<string, string>  $files */
    private function read(array $files): ModelUpload
    {
        $path = $this->directory.'/bundle-'.substr(md5(serialize($files)), 0, 8).'.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return ModelUpload::read($path, 'gltf');
    }

    /** @param  array<string, string>  $image */
    private function gltf(array $image): string
    {
        return (string) json_encode([
            'asset' => ['version' => '2.0'],
            'images' => [$image],
            'materials' => [['pbrMetallicRoughness' => ['baseColorTexture' => ['index' => 0]]]],
            'textures' => [['source' => 0]],
        ]);
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
