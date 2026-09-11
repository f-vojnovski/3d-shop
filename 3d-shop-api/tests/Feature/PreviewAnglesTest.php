<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Jobs\RenderProductPreviews;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PreviewAnglesTest extends TestCase
{
    use RefreshDatabase;

    private const ANGLE = [
        'position' => [3.2, 1.8, 4.1],
        'target' => [0, 0, 0],
        'up' => [0, 1, 0],
        'fov' => 75,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('models');
        Storage::fake('public');
    }

    private function seller(): User
    {
        $user = User::create([
            'name' => 'seller',
            'email' => 'seller@example.com',
            'password' => 'password123',
        ]);

        Sanctum::actingAs($user);

        return $user;
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

    public function test_angles_are_stored_as_numbers_and_returned(): void
    {
        $this->seller();

        // Multipart sends this as a JSON string, as the browser does.
        $id = $this->postJson('/api/products', $this->payload([
            'preview_mode' => Product::PREVIEW_ATTESTED_STILLS,
            'preview_angles' => json_encode(['obj' => [self::ANGLE]]),
        ]))->assertSuccessful()->json('id');

        $angles = Product::findOrFail($id)->deliverables()->first()->angles();

        $this->assertCount(1, $angles);
        $this->assertSame([3.2, 1.8, 4.1], $angles[0]['position']);
        $this->assertSame(75, $angles[0]['fov']);

        $this->assertSame(
            [3.2, 1.8, 4.1],
            $this->getJson("/api/products/{$id}")->json('previews.0.angles.0.position')
        );
    }

    public function test_a_product_defaults_to_no_angles(): void
    {
        $this->seller();

        $id = $this->postJson('/api/products', $this->payload())
            ->assertSuccessful()->json('id');

        $this->assertSame([], $this->getJson("/api/products/{$id}")->json('previews.0.angles'));
    }

    public function test_a_malformed_angle_is_rejected(): void
    {
        $this->seller();

        $this->postJson('/api/products', $this->payload([
            'preview_angles' => json_encode([
                'obj' => [['position' => [1, 2], 'target' => [0, 0, 0], 'fov' => 75]],
            ]),
        ]))->assertStatus(422)->assertJsonValidationErrors('preview_angles.obj.0.position');
    }

    public function test_a_nonsense_field_of_view_is_rejected(): void
    {
        $this->seller();

        $this->postJson('/api/products', $this->payload([
            'preview_angles' => json_encode(['obj' => [array_merge(self::ANGLE, ['fov' => 400])]]),
        ]))->assertStatus(422)->assertJsonValidationErrors('preview_angles.obj.0.fov');
    }

    public function test_a_nonsense_up_vector_is_rejected(): void
    {
        $this->seller();

        $this->postJson('/api/products', $this->payload([
            'preview_angles' => json_encode(['obj' => [array_merge(self::ANGLE, ['up' => [0, 500, 0]])]]),
        ]))->assertStatus(422)->assertJsonValidationErrors('preview_angles.obj.0.up.1');
    }

    public function test_an_up_vector_of_the_wrong_shape_is_rejected(): void
    {
        $this->seller();

        $this->postJson('/api/products', $this->payload([
            'preview_angles' => json_encode(['obj' => [array_merge(self::ANGLE, ['up' => [0, 1]])]]),
        ]))->assertStatus(422)->assertJsonValidationErrors('preview_angles.obj.0.up');
    }

    /** Optional, because every angle framed before this existed omits it. */
    public function test_an_angle_without_an_up_vector_is_still_accepted(): void
    {
        $this->seller();

        $this->postJson('/api/products', $this->payload([
            'preview_angles' => json_encode(['obj' => [self::ANGLE]]),
        ]))->assertSuccessful();
    }

    public function test_too_many_angles_are_rejected(): void
    {
        $this->seller();

        $this->postJson('/api/products', $this->payload([
            'preview_angles' => json_encode(['obj' => array_fill(0, 9, self::ANGLE)]),
        ]))->assertStatus(422)->assertJsonValidationErrors('preview_angles.obj');
    }

    public function test_the_owner_cannot_replace_the_angles_later(): void
    {
        $this->seller();
        $id = $this->postJson('/api/products', $this->payload([
            'preview_mode' => Product::PREVIEW_ATTESTED_STILLS,
            'preview_angles' => json_encode(['obj' => [self::ANGLE]]),
        ]))->assertSuccessful()->json('id');

        $this->putJson("/api/products/{$id}", [
            'preview_angles' => ['obj' => [self::ANGLE, self::ANGLE]],
        ])->assertStatus(422)->assertJsonValidationErrors('preview_angles');

        $this->assertCount(1, Product::findOrFail($id)->deliverables()->first()->angles());
    }

    public function test_uploading_attested_stills_queues_a_render(): void
    {
        $this->seller();

        $id = $this->postJson('/api/products', $this->payload([
            'preview_mode' => Product::PREVIEW_ATTESTED_STILLS,
            'preview_angles' => json_encode(['obj' => [self::ANGLE]]),
        ]))->assertSuccessful()->json('id');

        Queue::assertPushed(
            RenderProductPreviews::class,
            fn (RenderProductPreviews $job) => $job->productId === $id && $job->format === 'obj'
        );
    }

    public function test_an_interactive_upload_does_not_queue_a_render(): void
    {
        $this->seller();

        $this->postJson('/api/products', $this->payload())->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_attested_stills_cannot_be_published_without_an_angle(): void
    {
        $this->seller();

        $this->postJson('/api/products', $this->payload([
            'preview_mode' => Product::PREVIEW_ATTESTED_STILLS,
        ]))->assertStatus(422)->assertJsonValidationErrors('preview_angles.obj');

        Queue::assertNothingPushed();
    }

    public function test_the_preview_mode_is_settled_too(): void
    {
        $this->seller();
        $id = $this->postJson('/api/products', $this->payload())
            ->assertSuccessful()->json('id');

        $this->putJson("/api/products/{$id}", [
            'preview_mode' => Product::PREVIEW_ATTESTED_STILLS,
        ])->assertStatus(422)->assertJsonValidationErrors('preview_mode');

        $this->assertSame(Product::PREVIEW_INTERACTIVE, Product::findOrFail($id)->preview_mode);
    }

    public function test_the_listing_itself_is_still_editable(): void
    {
        $this->seller();
        $id = $this->postJson('/api/products', $this->payload())
            ->assertSuccessful()->json('id');

        $this->putJson("/api/products/{$id}", [
            'name' => 'Renamed',
            'price' => '31.00',
            'unlisted' => true,
        ])->assertSuccessful();

        $product = Product::findOrFail($id);
        $this->assertSame('Renamed', $product->name);
        $this->assertSame(3100, $product->price_cents);
        $this->assertTrue($product->unlisted);
    }

    public function test_replacing_a_model_file_is_refused(): void
    {
        $this->seller();
        $id = $this->postJson('/api/products', $this->payload())
            ->assertSuccessful()->json('id');

        $this->putJson("/api/products/{$id}", ['objModel' => 'anything'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('objModel');
    }
}
