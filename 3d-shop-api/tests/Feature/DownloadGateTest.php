<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductFile;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DownloadGateTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => $name.'@example.com',
            'password' => 'password123',
        ]);
    }

    private function upload(User $seller, array $extra = []): Product
    {
        Sanctum::actingAs($seller);

        $this->postJson('/api/products', array_merge([
            'name' => 'Half-track',
            'price' => '24.50',
            'objModel' => UploadedFile::fake()->createWithContent('model.obj', "v 0 0 0\n"),
            'thumbnail' => UploadedFile::fake()->image('thumb.png'),
        ], $extra))->assertSuccessful();

        return Product::with('files')->sole();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('models');
        Storage::fake('public');
    }

    public function test_geometry_is_stored_off_the_public_disk(): void
    {
        $product = $this->upload($this->user('seller'));

        $deliverable = $product->deliverableFor('obj');
        $thumbnail = $product->thumbnail();

        $this->assertSame('models', $deliverable->disk);
        $this->assertSame('public', $thumbnail->disk);

        Storage::disk('models')->assertExists($deliverable->path);
        Storage::disk('public')->assertMissing($deliverable->path);
    }

    public function test_uploads_record_size_and_checksum(): void
    {
        $product = $this->upload($this->user('seller'));
        $deliverable = $product->deliverableFor('obj');

        $this->assertGreaterThan(0, $deliverable->bytes);
        $this->assertSame(64, strlen($deliverable->checksum));
    }

    public function test_a_stranger_is_offered_no_download_url(): void
    {
        $product = $this->upload($this->user('seller'));
        app('auth')->forgetGuards();

        $body = $this->getJson("/api/products/{$product->id}")->json();

        $this->assertNull($body['download_urls']);
        $this->assertSame(Product::STATUS_NOT_PURCHASED, $body['product_status']);
    }

    public function test_the_owner_is_offered_a_download_url(): void
    {
        $seller = $this->user('seller');
        $product = $this->upload($seller);

        Sanctum::actingAs($seller);
        $body = $this->getJson("/api/products/{$product->id}")->json();

        $this->assertNotNull($body['download_urls']['obj']);
        $this->assertSame(Product::STATUS_OWNER, $body['product_status']);
    }

    public function test_a_buyer_is_offered_a_download_url_and_can_use_it(): void
    {
        $seller = $this->user('seller');
        $product = $this->upload($seller);
        $buyer = $this->user('buyer');

        Sale::create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'price_cents' => $product->price_cents,
        ]);

        Sanctum::actingAs($buyer);
        $url = $this->getJson("/api/products/{$product->id}")->json('download_urls.obj');

        $this->assertNotNull($url);
        $this->get($url)->assertSuccessful();
    }

    public function test_an_unsigned_download_is_rejected(): void
    {
        $product = $this->upload($this->user('seller'));

        $this->get("/api/products/{$product->id}/download/obj")->assertForbidden();
    }

    public function test_a_tampered_download_url_is_rejected(): void
    {
        $seller = $this->user('seller');
        $product = $this->upload($seller);

        Sanctum::actingAs($seller);
        $url = $this->getJson("/api/products/{$product->id}")->json('download_urls.obj');

        $this->get(str_replace('user='.$seller->id, 'user=999', $url))->assertForbidden();
    }

    public function test_a_download_url_expires(): void
    {
        $seller = $this->user('seller');
        $product = $this->upload($seller);

        Sanctum::actingAs($seller);
        $url = $this->getJson("/api/products/{$product->id}")->json('download_urls.obj');

        $this->travel(16)->minutes();

        $this->get($url)->assertForbidden();
    }

    public function test_an_interactive_product_serves_a_public_preview(): void
    {
        $product = $this->upload($this->user('seller'));
        app('auth')->forgetGuards();

        $url = $this->getJson("/api/products/{$product->id}")->json('preview_urls.obj');

        $this->assertNotNull($url);
        $this->get($url)->assertSuccessful();
    }

    public function test_an_attested_stills_product_offers_no_preview_url(): void
    {
        $product = $this->upload($this->user('seller'), [
            'preview_mode' => Product::PREVIEW_ATTESTED_STILLS,
            'preview_angles' => json_encode([
                ['position' => [3, 2, 4], 'target' => [0, 0, 0], 'fov' => 75],
            ]),
        ]);
        app('auth')->forgetGuards();

        $body = $this->getJson("/api/products/{$product->id}")->json();

        $this->assertSame([], $body['preview_urls']);
        $this->get("/api/products/{$product->id}/preview/obj")->assertForbidden();
    }
}
