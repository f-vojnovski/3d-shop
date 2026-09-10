<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SellerImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('models');
        Storage::fake('public');
    }

    public function test_a_seller_can_publish_their_own_images_alongside_the_model(): void
    {
        $id = $this->publish([
            'images' => [
                UploadedFile::fake()->image('beauty-shot.png'),
                UploadedFile::fake()->image('in-context.jpg'),
            ],
        ]);

        $images = Product::findOrFail($id)->sellerImages()->get();

        $this->assertCount(2, $images);
        $this->assertSame([0, 1], $images->pluck('sort')->all());

        foreach ($images as $image) {
            $this->assertSame('public', $image->disk);
            $this->assertStringStartsWith('seller_images/', $image->path);
            Storage::disk('public')->assertExists($image->path);
        }
    }

    public function test_the_payload_keeps_them_away_from_the_attested_stills(): void
    {
        $id = $this->publish(['images' => [UploadedFile::fake()->image('mine.png')]]);

        $body = $this->getJson("/api/products/{$id}")->assertSuccessful()->json();

        $this->assertCount(1, $body['seller_images']);
        $this->assertArrayNotHasKey('attestation_url', $body['seller_images'][0]);
        $this->assertSame([[]], array_column($body['previews'], 'images'));
    }

    public function test_a_product_without_them_reports_an_empty_list(): void
    {
        $id = $this->publish();

        $this->getJson("/api/products/{$id}")->assertSuccessful()->assertJsonPath('seller_images', []);
    }

    public function test_they_cannot_be_added_after_publishing(): void
    {
        $id = $this->publish();

        $this->putJson("/api/products/{$id}", ['images' => ['anything']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('images');

        $this->assertCount(0, Product::findOrFail($id)->sellerImages()->get());
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        Sanctum::actingAs($this->seller());

        $this->postJson('/api/products', $this->payload([
            'images' => [UploadedFile::fake()->create('notes.txt', 10, 'text/plain')],
        ]))->assertStatus(422)->assertJsonValidationErrors('images.0');
    }

    public function test_there_is_a_ceiling_on_how_many(): void
    {
        Sanctum::actingAs($this->seller());

        $this->postJson('/api/products', $this->payload([
            'images' => array_map(
                fn (int $index) => UploadedFile::fake()->image("shot-{$index}.png"),
                range(1, 9)
            ),
        ]))->assertStatus(422)->assertJsonValidationErrors('images');
    }

    public function test_deleting_the_product_takes_them_with_it(): void
    {
        $product = Product::findOrFail(
            $this->publish(['images' => [UploadedFile::fake()->image('mine.png')]])
        );

        $product->delete();

        $this->assertSame(0, ProductFile::where('product_id', $product->id)->count());
    }

    private function seller(): User
    {
        return User::create([
            'name' => 'seller',
            'email' => 'seller@example.com',
            'password' => 'password123',
        ]);
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'name' => 'Half-track',
            'price' => '24.50',
            'objModel' => UploadedFile::fake()->createWithContent('model.obj', "v 0 0 0\n"),
            'thumbnail' => UploadedFile::fake()->image('thumb.png'),
        ], $extra);
    }

    private function publish(array $extra = []): int
    {
        Sanctum::actingAs($this->seller());

        return $this->postJson('/api/products', $this->payload($extra))
            ->assertSuccessful()
            ->json('id');
    }
}
