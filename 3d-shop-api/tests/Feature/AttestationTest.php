<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttestationTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_HASH = 'df09c9fde1a937a4de99084c1129d77ac4a690f8c97804e89b99d11828bf9cd7';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('models');
        Storage::fake('public');
    }

    public function test_a_still_reports_what_it_was_rendered_from(): void
    {
        $preview = $this->attestedProduct()['preview'];

        $body = $this->getJson("/api/previews/{$preview->id}/attestation")
            ->assertSuccessful()
            ->json();

        $this->assertSame($preview->checksum, $body['image']['sha256']);
        $this->assertSame(75, $body['camera']['fov']);
        $this->assertSame('0.186.0', $body['renderer']['three']);
        $this->assertSame(self::SOURCE_HASH, $body['source_model']['sha256']);
        $this->assertTrue($body['source_model']['still_on_sale']);
    }

    public function test_it_reports_when_the_model_on_sale_is_no_longer_the_one_rendered(): void
    {
        $fixture = $this->attestedProduct();
        $fixture['source']->update(['checksum' => str_repeat('b', 64)]);

        $this->getJson("/api/previews/{$fixture['preview']->id}/attestation")
            ->assertSuccessful()
            ->assertJsonPath('source_model.still_on_sale', false);
    }

    public function test_only_rendered_stills_are_addressable(): void
    {
        $thumbnail = $this->attestedProduct()['product']->files()->create([
            'kind' => ProductFile::KIND_THUMBNAIL,
            'disk' => 'public',
            'path' => 'thumbnails/t.png',
            'bytes' => 10,
            'checksum' => str_repeat('c', 64),
        ]);

        $this->getJson("/api/previews/{$thumbnail->id}/attestation")->assertNotFound();
    }

    public function test_the_product_payload_links_each_still_to_its_record(): void
    {
        $fixture = $this->attestedProduct();

        $this->getJson("/api/products/{$fixture['product']->id}")
            ->assertSuccessful()
            ->assertJsonPath(
                'previews.0.images.0.attestation_url',
                "/api/previews/{$fixture['preview']->id}/attestation"
            );
    }

    public function test_a_wireframe_carries_the_same_record_as_its_still(): void
    {
        $fixture = $this->attestedProduct();
        $outline = $this->wireframeFor($fixture);

        $body = $this->getJson("/api/previews/{$outline->id}/attestation")
            ->assertSuccessful()
            ->json();

        $this->assertSame('wireframe', $body['image']['pass']);
        $this->assertSame(75, $body['camera']['fov']);
        $this->assertSame(self::SOURCE_HASH, $body['source_model']['sha256']);
        $this->assertTrue($body['source_model']['still_on_sale']);
    }

    public function test_a_still_says_which_pass_drew_it(): void
    {
        $preview = $this->attestedProduct()['preview'];

        $this->getJson("/api/previews/{$preview->id}/attestation")
            ->assertSuccessful()
            ->assertJsonPath('image.pass', 'shaded');
    }

    public function test_the_payload_pairs_each_still_with_its_wireframe(): void
    {
        $fixture = $this->attestedProduct();
        $outline = $this->wireframeFor($fixture);

        $this->getJson("/api/products/{$fixture['product']->id}")
            ->assertSuccessful()
            ->assertJsonPath('previews.0.images.0.wireframe.id', $outline->id)
            ->assertJsonPath(
                'previews.0.images.0.wireframe.attestation_url',
                "/api/previews/{$outline->id}/attestation"
            );
    }

    /** Products rendered before the pass existed have stills and nothing else. */
    public function test_a_still_with_no_wireframe_reports_none(): void
    {
        $fixture = $this->attestedProduct();

        $this->getJson("/api/products/{$fixture['product']->id}")
            ->assertSuccessful()
            ->assertJsonPath('previews.0.images.0.wireframe', null);
    }

    public function test_verifying_a_product_with_no_stills_fails_without_rendering(): void
    {
        $product = $this->attestedProduct()['product'];
        $product->previewImages()->delete();

        $this->artisan('render:verify', ['product' => $product->id])->assertExitCode(1);
    }

    public function test_verifying_an_unknown_product_fails(): void
    {
        $this->artisan('render:verify', ['product' => 999999])->assertExitCode(1);
    }

    private function wireframeFor(array $fixture): ProductFile
    {
        return $fixture['product']->files()->create([
            'source_file_id' => $fixture['source']->id,
            'kind' => ProductFile::KIND_WIREFRAME,
            'format' => 'png',
            'disk' => 'public',
            'path' => 'preview_images/a-wireframe.png',
            'sort' => 0,
            'bytes' => 24110,
            'checksum' => str_repeat('d', 64),
            'meta' => $fixture['preview']->meta,
        ]);
    }

    private function attestedProduct(): array
    {
        $user = User::create([
            'name' => 'seller',
            'email' => 'seller@example.com',
            'password' => 'password123',
        ]);

        $product = Product::create([
            'name' => 'Half-track',
            'price_cents' => 2450,
            'preview_mode' => Product::PREVIEW_ATTESTED_STILLS,
            'preview_angles' => [['position' => [3.2, 1.8, 4.1], 'target' => [0, 0, 0], 'fov' => 75]],
            'user_id' => $user->id,
        ]);

        $source = $product->files()->create([
            'kind' => ProductFile::KIND_DELIVERABLE,
            'format' => 'obj',
            'disk' => 'models',
            'path' => 'obj_files/model.obj',
            'bytes' => 1805882,
            'checksum' => self::SOURCE_HASH,
            'meta' => ['sniffed_format' => 'obj', 'faces' => 25940],
        ]);

        $preview = $product->files()->create([
            'source_file_id' => $source->id,
            'kind' => ProductFile::KIND_PREVIEW_IMAGE,
            'format' => 'png',
            'disk' => 'public',
            'path' => 'preview_images/a.png',
            'sort' => 0,
            'bytes' => 90917,
            'checksum' => str_repeat('a', 64),
            'meta' => [
                'camera' => ['position' => [3.2, 1.8, 4.1], 'target' => [0, 0, 0], 'fov' => 75],
                'coverage' => 0.06814,
                'renderer' => [
                    'engine' => 'three.js',
                    'three' => '0.186.0',
                    'browser' => 'Chromium 152.0.7977.82',
                    'rasterizer' => 'swiftshader',
                ],
                'source_checksum' => self::SOURCE_HASH,
            ],
        ]);

        return ['product' => $product, 'source' => $source, 'preview' => $preview];
    }
}
