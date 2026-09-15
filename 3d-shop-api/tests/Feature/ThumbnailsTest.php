<?php

namespace Tests\Feature;

use App\Jobs\ScaleThumbnail;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ThumbnailsTest extends TestCase
{
    use RefreshDatabase;

    private ?User $seller = null;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('models');
        Storage::fake('public');
    }

    public function test_a_seller_adds_more_pictures_from_their_machine(): void
    {
        $id = $this->publish();

        $body = $this->postJson("/api/products/{$id}/thumbnails", [
            'images' => [
                UploadedFile::fake()->image('front.png'),
                UploadedFile::fake()->image('back.png'),
            ],
        ])->assertSuccessful()->json();

        $this->assertCount(3, $body['thumbnails']);
        $this->assertSame([0, 1, 2], array_column($body['thumbnails'], 'sort'));
    }

    /** A header claiming 30,000 square is read before the file is kept at all. */
    public function test_a_picture_that_claims_more_pixels_than_it_holds_is_refused(): void
    {
        $id = $this->publish();

        $before = ProductFile::where('kind', ProductFile::KIND_THUMBNAIL)->count();

        $this->postJson("/api/products/{$id}/thumbnails", [
            'images' => [
                UploadedFile::fake()->createWithContent('huge.png', $this->pngClaiming(30000, 30000)),
            ],
        ])->assertStatus(422);

        $this->assertSame(
            $before,
            ProductFile::where('kind', ProductFile::KIND_THUMBNAIL)->count(),
            'The picture was stored and left for the shrinker to refuse later.'
        );
    }

    public function test_every_added_picture_is_queued_for_shrinking(): void
    {
        $id = $this->publish();

        $this->postJson("/api/products/{$id}/thumbnails", [
            'images' => [UploadedFile::fake()->image('front.png')],
        ])->assertSuccessful();

        Queue::assertPushed(ScaleThumbnail::class, 2);
    }

    public function test_a_seller_can_use_a_render_as_a_card_picture(): void
    {
        $id = $this->publish();
        $still = $this->still($id);

        $body = $this->postJson("/api/products/{$id}/thumbnails", ['from' => [$still->id]])
            ->assertSuccessful()
            ->json();

        $this->assertCount(2, $body['thumbnails']);
    }

    /**
     * The scaling job rewrites what a thumbnail holds, so a render it was taken
     * from has to be copied. An attested image cannot change after the fact.
     */
    public function test_using_a_render_copies_it_rather_than_sharing_the_bytes(): void
    {
        $id = $this->publish();
        $still = $this->still($id);

        $this->postJson("/api/products/{$id}/thumbnails", ['from' => [$still->id]])
            ->assertSuccessful();

        $added = Product::findOrFail($id)->thumbnails()->orderByDesc('sort')->first();

        $this->assertNotSame($still->path, $added->path);

        (new ScaleThumbnail($added->id))->handle();

        Storage::disk('public')->assertExists($still->path);
        $this->assertSame('rendered-bytes', Storage::disk('public')->get($still->path));
    }

    public function test_a_picture_from_another_product_is_refused(): void
    {
        $mine = $this->publish();
        $owner = $this->seller;
        $theirs = $this->still($this->publish());

        Sanctum::actingAs($owner);

        $this->postJson("/api/products/{$mine}/thumbnails", ['from' => [$theirs->id]])
            ->assertStatus(422);
    }

    public function test_a_request_that_names_nothing_is_refused(): void
    {
        $id = $this->publish();

        $this->postJson("/api/products/{$id}/thumbnails", [])->assertStatus(422);
    }

    public function test_only_the_owner_may_change_the_card_pictures(): void
    {
        $id = $this->publish();

        Sanctum::actingAs(User::create([
            'name' => 'someone else',
            'email' => 'else@example.com',
            'password' => 'password123',
        ]));

        $this->postJson("/api/products/{$id}/thumbnails", [
            'images' => [UploadedFile::fake()->image('mine.png')],
        ])->assertStatus(403);
    }

    public function test_a_seller_takes_one_off_the_card(): void
    {
        $id = $this->publish();
        $first = Product::findOrFail($id)->thumbnails()->firstOrFail();

        $this->postJson("/api/products/{$id}/thumbnails", [
            'images' => [UploadedFile::fake()->image('second.png')],
        ])->assertSuccessful();

        $body = $this->deleteJson("/api/products/{$id}/thumbnails/{$first->id}")
            ->assertSuccessful()
            ->json();

        $this->assertCount(1, $body['thumbnails']);
        Storage::disk('public')->assertMissing($first->path);
    }

    /** A listing with no picture is a blank card in the grid. */
    public function test_the_last_picture_cannot_be_taken_off(): void
    {
        $id = $this->publish();
        $only = Product::findOrFail($id)->thumbnails()->firstOrFail();

        $this->deleteJson("/api/products/{$id}/thumbnails/{$only->id}")->assertStatus(422);

        Storage::disk('public')->assertExists($only->path);
        $this->assertSame(1, Product::findOrFail($id)->thumbnails()->count());
    }

    public function test_a_seller_publishes_with_several_pictures_at_once(): void
    {
        $id = $this->publish([
            'thumbnails' => [
                UploadedFile::fake()->image('one.png'),
                UploadedFile::fake()->image('two.png'),
                UploadedFile::fake()->image('three.png'),
            ],
        ]);

        $this->assertSame(3, Product::findOrFail($id)->thumbnails()->count());
        Queue::assertPushed(ScaleThumbnail::class, 3);
    }

    public function test_the_job_shrinks_a_picture_too_big_for_a_card(): void
    {
        $id = $this->publish(['thumbnails' => [UploadedFile::fake()->image('huge.png', 1600, 1200)]]);
        $thumbnail = Product::findOrFail($id)->thumbnails()->firstOrFail();
        $before = $thumbnail->bytes;

        (new ScaleThumbnail($thumbnail->id))->handle();

        $after = $thumbnail->fresh();

        $this->assertLessThan($before, $after->bytes);
        $this->assertTrue($after->meta['scaled']);

        $size = getimagesizefromstring(Storage::disk('public')->get($after->path));

        $this->assertSame(ScaleThumbnail::EDGE, max($size[0], $size[1]));
    }

    public function test_the_job_leaves_a_picture_that_is_already_small_alone(): void
    {
        $id = $this->publish(['thumbnails' => [UploadedFile::fake()->image('small.png', 200, 150)]]);
        $thumbnail = Product::findOrFail($id)->thumbnails()->firstOrFail();

        (new ScaleThumbnail($thumbnail->id))->handle();

        $after = $thumbnail->fresh();

        $this->assertSame($thumbnail->path, $after->path);
        $this->assertTrue($after->meta['scaled']);
    }

    /** Re-encoding a photograph as PNG makes it larger than what it replaced. */
    public function test_a_photograph_comes_back_as_a_photograph(): void
    {
        $id = $this->publish(['thumbnails' => [UploadedFile::fake()->image('photo.jpg', 1600, 1200)]]);
        $thumbnail = Product::findOrFail($id)->thumbnails()->firstOrFail();

        (new ScaleThumbnail($thumbnail->id))->handle();

        $this->assertStringEndsWith('.jpg', $thumbnail->fresh()->path);
    }

    public function test_running_the_job_twice_changes_nothing_the_second_time(): void
    {
        $id = $this->publish(['thumbnails' => [UploadedFile::fake()->image('huge.png', 1600, 1200)]]);
        $thumbnail = Product::findOrFail($id)->thumbnails()->firstOrFail();

        (new ScaleThumbnail($thumbnail->id))->handle();
        $once = $thumbnail->fresh();

        (new ScaleThumbnail($thumbnail->id))->handle();

        $this->assertSame($once->path, $thumbnail->fresh()->path);
    }

    /**
     * 33 bytes of PNG header claiming 900 million pixels. A real one asks GD
     * for 3.6 GB, so the declared size is what gets refused.
     */
    public function test_a_small_file_claiming_an_enormous_picture_is_refused(): void
    {
        Log::spy();

        $id = $this->publish(['thumbnails' => [UploadedFile::fake()->image('real.png', 900, 700)]]);
        $thumbnail = Product::findOrFail($id)->thumbnails()->firstOrFail();

        Storage::disk('public')->put($thumbnail->path, $this->pngClaiming(30000, 30000));

        (new ScaleThumbnail($thumbnail->id))->handle();

        $after = $thumbnail->fresh();

        $this->assertSame($thumbnail->path, $after->path);
        $this->assertTrue($after->meta['scaled']);

        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $context = []) => is_string($message)
                && str_contains($message, 'too large to open')
                && ($context['width'] ?? null) === 30000
        )->once();
    }

    /** A picture right on the limit still goes through, so the cap is not a wall. */
    public function test_a_large_but_believable_picture_is_still_shrunk(): void
    {
        $id = $this->publish(['thumbnails' => [UploadedFile::fake()->image('big.png', 3000, 2000)]]);
        $thumbnail = Product::findOrFail($id)->thumbnails()->firstOrFail();

        (new ScaleThumbnail($thumbnail->id))->handle();

        $size = getimagesizefromstring(Storage::disk('public')->get($thumbnail->fresh()->path));

        $this->assertSame(ScaleThumbnail::EDGE, max($size[0], $size[1]));
    }

    /** Only a PNG header: enough for getimagesize, nothing anything can decode. */
    private function pngClaiming(int $width, int $height): string
    {
        $header = pack('N', $width).pack('N', $height).pack('C5', 8, 2, 0, 0, 0);
        $chunk = pack('N', 13).'IHDR'.$header.pack('N', crc32('IHDR'.$header));

        return pack('C8', 0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A).$chunk;
    }

    private function still(int $productId): ProductFile
    {
        $path = 'preview_images/'.uniqid().'.png';
        Storage::disk('public')->put($path, 'rendered-bytes');

        return Product::findOrFail($productId)->files()->create([
            'kind' => ProductFile::KIND_PREVIEW_IMAGE,
            'format' => 'png',
            'disk' => 'public',
            'path' => $path,
            'sort' => 0,
            'bytes' => 14,
            'checksum' => hash('sha256', 'rendered-bytes'),
        ]);
    }

    private function publish(array $extra = []): int
    {
        $this->seller = User::create([
            'name' => 'seller'.uniqid(),
            'email' => uniqid().'@example.com',
            'password' => 'password123',
        ]);

        Sanctum::actingAs($this->seller);

        return $this->postJson('/api/products', array_merge([
            'name' => 'Half-track',
            'price' => '24.50',
            'objModel' => UploadedFile::fake()->createWithContent('model.obj', "v 0 0 0\n"),
            'thumbnails' => [UploadedFile::fake()->image('thumb.png')],
        ], $extra))->assertSuccessful()->json('id');
    }
}
