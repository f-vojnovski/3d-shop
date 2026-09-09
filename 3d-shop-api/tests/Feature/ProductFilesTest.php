<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductFilesTest extends TestCase
{
    use RefreshDatabase;

    private function productWithFiles(): Product
    {
        $user = User::create([
            'name' => 'seller',
            'email' => 'seller@example.com',
            'password' => 'password123',
        ]);

        $product = Product::create([
            'name' => 'Half-track',
            'price_cents' => 2450,
            'user_id' => $user->id,
        ]);

        $product->files()->createMany([
            ['kind' => ProductFile::KIND_PREVIEW_IMAGE, 'disk' => 'public', 'path' => 'b.png', 'sort' => 2],
            ['kind' => ProductFile::KIND_DELIVERABLE, 'disk' => 'private', 'path' => 'model.glb', 'format' => 'glb'],
            ['kind' => ProductFile::KIND_PREVIEW_IMAGE, 'disk' => 'public', 'path' => 'a.png', 'sort' => 1],
        ]);

        return $product;
    }

    public function test_deliverables_are_separated_from_previews(): void
    {
        $product = $this->productWithFiles();

        $this->assertCount(3, $product->files);
        $this->assertSame(['model.glb'], $product->deliverables->pluck('path')->all());
    }

    public function test_preview_images_come_back_in_the_chosen_order(): void
    {
        $product = $this->productWithFiles();

        $this->assertSame(['a.png', 'b.png'], $product->previewImages->pluck('path')->all());
    }

    public function test_products_default_to_interactive_preview(): void
    {
        $product = $this->productWithFiles();

        $this->assertSame(Product::PREVIEW_INTERACTIVE, $product->fresh()->preview_mode);
    }

    public function test_deleting_a_product_removes_its_files(): void
    {
        $product = $this->productWithFiles();

        $product->delete();

        $this->assertSame(0, ProductFile::where('product_id', $product->id)->count());
    }

    public function test_camera_parameters_round_trip_through_meta(): void
    {
        $product = $this->productWithFiles();
        $camera = ['position' => [3.2, 1.8, 4.1], 'target' => [0, 0, 0], 'fov' => 75];

        $file = $product->files()->create([
            'kind' => ProductFile::KIND_PREVIEW_IMAGE,
            'disk' => 'public',
            'path' => 'angle.png',
            'meta' => ['camera' => $camera],
        ]);

        $this->assertSame($camera, $file->fresh()->meta['camera']);
    }
}
