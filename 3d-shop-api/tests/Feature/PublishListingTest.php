<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublishListingTest extends TestCase
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

    /**
     * The point of the whole change: a model used to be on sale the instant the
     * upload request returned, while its pictures were still being rendered.
     */
    public function test_an_upload_is_a_draft_until_the_seller_says_otherwise(): void
    {
        $id = $this->publish();

        $product = Product::findOrFail($id);

        $this->assertTrue($product->unlisted);
        $this->assertNull($product->published_at);
        $this->assertSame('draft', $product->listingStatus());
    }

    public function test_a_draft_is_not_in_the_catalogue(): void
    {
        $id = $this->publish();

        $this->assertNotContains($id, $this->catalogueIds());
    }

    public function test_publishing_puts_it_in_the_catalogue(): void
    {
        $id = $this->publish();

        $this->postJson("/api/products/{$id}/publish")->assertSuccessful();

        $this->assertContains($id, $this->catalogueIds());
        $this->assertSame('live', Product::findOrFail($id)->listingStatus());
    }

    public function test_a_seller_can_go_live_straight_from_the_upload(): void
    {
        $id = $this->publish(['publish' => '1']);

        $product = Product::findOrFail($id);

        $this->assertFalse($product->unlisted);
        $this->assertNotNull($product->published_at);
        $this->assertContains($id, $this->catalogueIds());
    }

    /** Withdrawing does not un-happen the fact that buyers once saw it. */
    public function test_withdrawing_after_publishing_reads_as_withdrawn_not_draft(): void
    {
        $id = $this->publish();
        $this->postJson("/api/products/{$id}/publish")->assertSuccessful();

        $first = Product::findOrFail($id)->published_at;

        $this->deleteJson("/api/products/{$id}")->assertSuccessful();

        $product = Product::findOrFail($id);

        $this->assertSame('withdrawn', $product->listingStatus());
        $this->assertEquals($first, $product->published_at);
        $this->assertNotContains($id, $this->catalogueIds());
    }

    public function test_a_withdrawn_listing_can_be_put_back_without_moving_its_first_date(): void
    {
        $id = $this->publish();
        $this->postJson("/api/products/{$id}/publish")->assertSuccessful();
        $first = Product::findOrFail($id)->published_at;

        $this->deleteJson("/api/products/{$id}")->assertSuccessful();
        $this->postJson("/api/products/{$id}/publish")->assertSuccessful();

        $product = Product::findOrFail($id);

        $this->assertSame('live', $product->listingStatus());
        $this->assertEquals($first, $product->published_at);
    }

    /** A live listing with no picture is a blank card in the grid. */
    public function test_a_listing_with_no_picture_cannot_go_live(): void
    {
        $id = $this->publish();
        $product = Product::findOrFail($id);
        $product->thumbnails()->each(fn ($one) => $one->delete());

        $this->postJson("/api/products/{$id}/publish")
            ->assertStatus(422)
            ->assertJsonValidationErrors('listing');

        $this->assertSame('draft', $product->fresh()->listingStatus());
    }

    public function test_only_the_owner_may_publish(): void
    {
        $id = $this->publish();

        Sanctum::actingAs(User::create([
            'name' => 'someone else',
            'email' => 'else@example.com',
            'password' => 'password123',
        ]));

        $this->postJson("/api/products/{$id}/publish")->assertStatus(403);
        $this->assertSame('draft', Product::findOrFail($id)->listingStatus());
    }

    public function test_the_payload_tells_the_seller_which_of_the_two_hidden_states_it_is(): void
    {
        $id = $this->publish();

        $this->assertSame('draft', $this->getJson("/api/products/{$id}")->json('listing_status'));

        $this->postJson("/api/products/{$id}/publish")->assertSuccessful();
        $this->assertSame('live', $this->getJson("/api/products/{$id}")->json('listing_status'));

        $this->deleteJson("/api/products/{$id}")->assertSuccessful();
        $this->assertSame('withdrawn', $this->getJson("/api/products/{$id}")->json('listing_status'));
    }

    /** Drafts are still the seller's own work, so they stay on their page. */
    public function test_a_draft_still_shows_in_my_uploads(): void
    {
        $id = $this->publish();

        $mine = collect($this->getJson('/api/current-user-products')->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($id, $mine);
    }

    /** @return list<int> */
    private function catalogueIds(): array
    {
        return collect($this->getJson('/api/products')->json('data'))->pluck('id')->all();
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
