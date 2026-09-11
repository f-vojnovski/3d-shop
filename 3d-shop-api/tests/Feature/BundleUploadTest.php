<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

/** A model and its textures, which is how they actually ship. */
class BundleUploadTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('models');
        Storage::fake('public');

        $this->directory = storage_path('framework/testing/uploads');
        File::ensureDirectoryExists($this->directory);

        Sanctum::actingAs(User::create([
            'name' => 'seller',
            'email' => 'seller@example.com',
            'password' => 'password123',
        ]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        File::deleteDirectory(storage_path('app/private/bundle-read'));

        parent::tearDown();
    }

    public function test_a_bundle_is_accepted_and_measured_from_the_model_inside(): void
    {
        $id = $this->publish($this->bundle());

        $file = Product::with('files')->findOrFail($id)->deliverableFor('obj');

        $this->assertNotNull($file);
        // The archive is what the buyer downloads, so that is what is stored.
        $this->assertStringEndsWith('.zip', $file->path);
        $this->assertSame('obj', $file->format);

        // Measured from the .obj inside, not from the archive.
        $this->assertSame(1, $file->facts()['faces']);
        $this->assertSame(3, $file->facts()['vertices']);
    }

    public function test_the_record_names_every_file_the_bundle_holds(): void
    {
        $id = $this->publish($this->bundle());

        $bundle = Product::with('files')->findOrFail($id)->deliverableFor('obj')->meta['bundle'];

        $this->assertSame('car/car.obj', $bundle['entry']);
        $this->assertSame(
            ['car/car.mtl', 'car/car.obj', 'car/textures/body.png'],
            array_column($bundle['files'], 'path')
        );
        $this->assertSame(64, strlen($bundle['digest']));
    }

    /** The one measurement a bare `.obj` cannot give, because it ships alone. */
    public function test_a_bundle_is_measured_for_materials_and_texture_size(): void
    {
        $id = $this->publish($this->bundle([
            'car/car.obj' => "mtllib car.mtl\nv 0 0 0\nv 1 0 0\nv 0 1 0\nf 1 2 3\n",
            'car/car.mtl' => "newmtl body\nmap_Kd textures/body.png\nnewmtl glass\nmap_Kd -s 1 1 1 textures/body.png\n",
            'car/textures/body.png' => $this->png(64, 32),
        ]));

        $facts = Product::with('files')->findOrFail($id)->deliverableFor('obj')->facts();

        $this->assertSame(2, $facts['materials']);
        // One image, named twice, and options on the line do not hide it.
        $this->assertSame([['width' => 64, 'height' => 32]], $facts['textures']);
    }

    /** The artist's own drive is not ours to read, and not the buyer's to get. */
    public function test_a_texture_outside_the_bundle_is_not_counted(): void
    {
        $id = $this->publish($this->bundle([
            'car/car.obj' => "mtllib car.mtl\nv 0 0 0\nv 1 0 0\nv 0 1 0\nf 1 2 3\n",
            'car/car.mtl' => "newmtl body\nmap_Kd t:\\pov3dmodel\\textures\\stone.jpg\nmap_Kd ../../escape.png\n",
        ]));

        $facts = Product::with('files')->findOrFail($id)->deliverableFor('obj')->facts();

        $this->assertSame(1, $facts['materials']);
        $this->assertSame([], $facts['textures']);
    }

    /**
     * The shape of a real broken pack: the material file names textures on the
     * artist's own drive, and the archive carries the textures under other
     * names. Either half alone is a shrug; together they are the diagnosis.
     */
    public function test_the_seller_is_told_what_failed_and_what_the_archive_holds(): void
    {
        $id = $this->publish($this->bundle([
            'car/car.obj' => "mtllib car.mtl\nv 0 0 0\nv 1 0 0\nv 0 1 0\nf 1 2 3\n",
            'car/car.mtl' => "newmtl body\nmap_Kd t:\\pov3dmodel\\textures\\stone_lime_02.jpg\n",
            'car/textures/stone_granite_red_grey_01.png' => $this->png(8, 8),
        ]));

        $preview = $this->getJson("/api/products-authenticated/{$id}")->json('previews.0');

        // Reported exactly as the material file wrote it, so the seller can find
        // the line and fix it.
        $this->assertSame(['t:\\pov3dmodel\\textures\\stone_lime_02.jpg'], $preview['missing']);
        $this->assertSame(['car/textures/stone_granite_red_grey_01.png'], $preview['unused_images']);
    }

    public function test_a_buyer_is_not_shown_the_unused_images(): void
    {
        $id = $this->publish($this->bundle([
            'car/car.obj' => "mtllib car.mtl\nv 0 0 0\nv 1 0 0\nv 0 1 0\nf 1 2 3\n",
            'car/car.mtl' => "newmtl body\nmap_Kd missing.png\n",
            'car/textures/spare.png' => $this->png(8, 8),
        ]));

        Sanctum::actingAs(User::create([
            'name' => 'buyer',
            'email' => 'buyer@example.com',
            'password' => 'password123',
        ]));

        $preview = $this->getJson("/api/products/{$id}")->json('previews.0');

        $this->assertSame(['missing.png'], $preview['missing']);
        $this->assertNull($preview['unused_images']);
    }

    public function test_a_bundle_whose_textures_all_resolve_reports_nothing(): void
    {
        $id = $this->publish($this->bundle([
            'car/car.obj' => "mtllib car.mtl\nv 0 0 0\nv 1 0 0\nv 0 1 0\nf 1 2 3\n",
            'car/car.mtl' => "newmtl body\nmap_Kd textures/body.png\n",
            'car/textures/body.png' => $this->png(16, 16),
        ]));

        $preview = $this->getJson("/api/products-authenticated/{$id}")->json('previews.0');

        $this->assertNull($preview['missing']);
        $this->assertNull($preview['unused_images']);
    }

    public function test_a_plain_model_carries_no_bundle_record(): void
    {
        $id = $this->publish(
            UploadedFile::fake()->createWithContent('car.obj', "v 0 0 0\nv 1 0 0\nv 0 1 0\nf 1 2 3\n")
        );

        $file = Product::with('files')->findOrFail($id)->deliverableFor('obj');

        $this->assertArrayNotHasKey('bundle', $file->meta);
        $this->assertStringEndsWith('.obj', $file->path);
    }

    public function test_an_archive_with_no_model_of_that_format_is_refused(): void
    {
        $this->postJson('/api/products', $this->payload($this->bundle([
            'car/readme.txt' => 'hello',
            'car/textures/body.png' => 'PNG',
        ])))->assertStatus(422)->assertJsonValidationErrors('objModel');
    }

    public function test_an_archive_holding_two_models_asks_the_seller_to_choose(): void
    {
        $this->postJson('/api/products', $this->payload($this->bundle([
            'car/one.obj' => "v 0 0 0\nf 1 1 1\n",
            'car/two.obj' => "v 0 0 0\nf 1 1 1\n",
        ])))->assertStatus(422)->assertJsonValidationErrors('objModel');
    }

    /** The gate runs before anything is stored. */
    public function test_a_hostile_archive_never_reaches_storage(): void
    {
        $this->postJson('/api/products', $this->payload($this->bundle([
            '../escaped.txt' => 'x',
            'car/car.obj' => "v 0 0 0\nf 1 1 1\n",
        ])))->assertStatus(422)->assertJsonValidationErrors('objModel');

        $this->assertSame(0, Product::count());
        $this->assertSame([], Storage::disk('models')->allFiles());
    }

    public function test_an_archive_holding_a_model_of_the_wrong_format_is_refused(): void
    {
        $this->postJson('/api/products', $this->payload($this->bundle([
            'car/car.glb' => 'glTF binary-ish',
        ])))->assertStatus(422)->assertJsonValidationErrors('objModel');
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** @param  array<string, string>|null  $files */
    private function bundle(?array $files = null): UploadedFile
    {
        $files ??= [
            'car/car.obj' => "v 0 0 0\nv 1 0 0\nv 0 1 0\nf 1 2 3\n",
            'car/car.mtl' => "newmtl body\nmap_Kd textures/body.png\n",
            'car/textures/body.png' => 'PNG-BYTES',
        ];

        $path = $this->directory.'/bundle-'.substr(md5(serialize($files)), 0, 8).'.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return new UploadedFile($path, 'car.zip', 'application/zip', null, true);
    }

    private function publish(UploadedFile $model): int
    {
        return $this->postJson('/api/products', $this->payload($model))
            ->assertSuccessful()
            ->json('id');
    }

    /** @return array<string, mixed> */
    private function payload(UploadedFile $model): array
    {
        return [
            'name' => 'Concept car',
            'price' => '49.00',
            'preview_mode' => Product::PREVIEW_ATTESTED_STILLS,
            'objModel' => $model,
            'preview_angles' => json_encode(['obj' => [[
                'position' => [3, 2, 3], 'target' => [0, 0, 0], 'fov' => 75,
            ]]]),
        ];
    }
}
