<?php

namespace Tests\Feature;

use App\Jobs\RenderProductPreviews;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReplaceFileTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('models');
        Storage::fake('public');

        $this->seller = User::create([
            'name' => 'seller',
            'email' => 'seller@example.com',
            'password' => 'password123',
        ]);
        Sanctum::actingAs($this->seller);

        $id = $this->postJson('/api/products', [
            'name' => 'Concept car',
            'price' => '129.00',
            'preview_mode' => Product::PREVIEW_ATTESTED_STILLS,
            'objModel' => UploadedFile::fake()->createWithContent('car.obj', "v 0 0 0\nf 1 1 1\n"),
            'thumbnail' => UploadedFile::fake()->image('thumb.png'),
            'standard_views' => ['obj'],
        ])->assertSuccessful()->json('id');

        $this->product = Product::with('files')->findOrFail($id);
        $this->markRendered();
    }

    public function test_the_owner_can_replace_a_file(): void
    {
        $this->replace()->assertSuccessful();

        $product = $this->product->fresh(['files']);

        $this->assertCount(1, $product->deliverables()->get());
        $this->assertCount(1, $product->supersededDeliverables()->get());
        $this->assertSame("v 1 1 1\nf 1 1 1\n", Storage::disk('models')->get(
            $product->deliverableFor('obj')->path
        ));
    }

    public function test_the_replaced_file_is_kept_rather_than_deleted(): void
    {
        $before = $this->product->deliverableFor('obj');

        $this->replace(['note' => 'Fixed the scale.'])->assertSuccessful();

        $old = $before->fresh();

        Storage::disk('models')->assertExists($old->path);
        $this->assertNotNull($old->superseded_at);
        $this->assertSame('Fixed the scale.', $old->replacement_note);
        $this->assertSame(
            $this->product->fresh(['files'])->deliverableFor('obj')->id,
            $old->superseded_by_id
        );
    }

    /** A camera is a property of the listing, not of the file it framed. */
    public function test_the_cameras_carry_over_so_the_views_stay_comparable(): void
    {
        $before = $this->product->deliverableFor('obj')->angles();

        $this->replace()->assertSuccessful();

        $this->assertSame($before, $this->product->fresh(['files'])->deliverableFor('obj')->angles());
    }

    public function test_replacing_queues_a_fresh_render(): void
    {
        $this->replace()->assertSuccessful();

        Queue::assertPushed(
            RenderProductPreviews::class,
            fn (RenderProductPreviews $job) => $job->productId === $this->product->id
                && $job->format === 'obj'
        );
    }

    public function test_the_old_previews_leave_the_listing_but_stay_on_the_record(): void
    {
        $still = $this->stillFor($this->product->deliverableFor('obj'));

        $this->replace(['note' => 'Reworked the wheels.'])->assertSuccessful();

        $body = $this->getJson("/api/products/{$this->product->id}")->assertSuccessful()->json();

        $this->assertSame([], $body['previews'][0]['images']);
        $this->assertCount(1, $body['previews'][0]['replaced']);
        $this->assertSame('Reworked the wheels.', $body['previews'][0]['replaced'][0]['note']);
        $this->assertCount(1, $body['previews'][0]['replaced'][0]['images']);

        $this->getJson("/api/previews/{$still->id}/attestation")
            ->assertSuccessful()
            ->assertJsonPath('source_model.still_on_sale', false)
            ->assertJsonPath('source_model.replacement_note', 'Reworked the wheels.');
    }

    public function test_a_stranger_cannot_replace_anything(): void
    {
        Sanctum::actingAs(User::create([
            'name' => 'someone',
            'email' => 'someone@example.com',
            'password' => 'password123',
        ]));

        $this->replace()->assertStatus(403);
        $this->assertCount(0, $this->product->fresh(['files'])->supersededDeliverables()->get());
    }

    public function test_a_format_the_product_does_not_have_is_refused(): void
    {
        $this->replace(['format' => 'gltf'])->assertStatus(422)->assertJsonValidationErrors('format');
    }

    /** The bytes have to agree with the format they are replacing. */
    public function test_a_file_of_the_wrong_kind_is_refused(): void
    {
        $this->replace(['model' => UploadedFile::fake()->createWithContent('car.obj', 'just prose')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('objModel');
    }

    public function test_nothing_can_change_while_a_render_is_in_flight(): void
    {
        $this->product->update(['preview_status' => 'rendering']);

        $this->replace()->assertStatus(422)->assertJsonValidationErrors('model');
        $this->putJson("/api/products/{$this->product->id}", ['name' => 'Renamed'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_replacing_twice_keeps_both_of_the_old_files(): void
    {
        $this->replace(['note' => 'First fix'])->assertSuccessful();
        $this->markRendered();
        $this->replace(['note' => 'Second fix'])->assertSuccessful();

        $product = $this->product->fresh(['files']);

        $this->assertCount(2, $product->supersededDeliverables()->get());
        $this->assertCount(1, $product->deliverables()->get());

        $notes = array_column(
            $this->getJson("/api/products/{$this->product->id}")->json('previews.0.replaced'),
            'note'
        );
        $this->assertSame(['Second fix', 'First fix'], $notes);
    }

    public function test_the_replacement_is_measured_like_any_other_upload(): void
    {
        $this->replace([
            'model' => UploadedFile::fake()->createWithContent(
                'car.obj',
                "v 0 0 0\nv 2 0 0\nv 0 4 0\nvt 0 0\nf 1/1 2/1 3/1\n"
            ),
        ])->assertSuccessful();

        $facts = $this->product->fresh(['files'])->deliverableFor('obj')->facts();

        $this->assertSame(3, $facts['vertices']);
        $this->assertTrue($facts['uvs']);
        // Loose: json_encode drops the zero fraction, so 2.0 returns as 2.
        $this->assertEquals([2, 4, 0], $facts['bounds']['size']);
    }

    private function markRendered(): void
    {
        $this->product->update(['preview_status' => 'ready']);
        $this->product->refresh()->load('files');
    }

    private function stillFor(ProductFile $source): ProductFile
    {
        return $this->product->files()->create([
            'source_file_id' => $source->id,
            'kind' => ProductFile::KIND_PREVIEW_IMAGE,
            'format' => 'png',
            'disk' => 'public',
            'path' => 'preview_images/'.uniqid().'.png',
            'sort' => 0,
            'bytes' => 3,
            'checksum' => hash('sha256', 'png'),
            'meta' => ['source_checksum' => $source->checksum, 'source_format' => $source->format],
        ]);
    }

    private function replace(array $extra = [])
    {
        return $this->postJson("/api/products/{$this->product->id}/replace", array_merge([
            'format' => 'obj',
            'model' => UploadedFile::fake()->createWithContent('car.obj', "v 1 1 1\nf 1 1 1\n"),
        ], $extra));
    }
}
