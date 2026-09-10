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

class PerFormatPreviewsTest extends TestCase
{
    use RefreshDatabase;

    private const ANGLE = [
        'position' => [3.2, 1.8, 4.1],
        'target' => [0, 0, 0],
        'fov' => 75,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('models');
        Storage::fake('public');
    }

    public function test_each_format_gets_its_own_angles_and_its_own_render(): void
    {
        $id = $this->upload([
            'obj' => [self::ANGLE],
            'gltf' => [self::ANGLE, array_merge(self::ANGLE, ['fov' => 40])],
        ]);

        $product = Product::with('files')->findOrFail($id);

        $this->assertCount(1, $product->deliverables()->where('format', 'obj')->first()->angles());
        $this->assertCount(2, $product->deliverables()->where('format', 'gltf')->first()->angles());

        foreach (['obj', 'gltf'] as $format) {
            Queue::assertPushed(
                RenderProductPreviews::class,
                fn (RenderProductPreviews $job) => $job->productId === $id && $job->format === $format
            );
        }
    }

    public function test_a_format_left_without_angles_is_refused_by_name(): void
    {
        Sanctum::actingAs($this->seller());

        $this->postJson('/api/products', $this->payload([
            'preview_mode' => Product::PREVIEW_ATTESTED_STILLS,
            'gltfModel' => UploadedFile::fake()->createWithContent('model.glb', 'glTF binary'),
            'preview_angles' => json_encode(['obj' => [self::ANGLE]]),
        ]))->assertStatus(422)->assertJsonValidationErrors('preview_angles.gltf');

        Queue::assertNothingPushed();
    }

    public function test_only_the_format_whose_angles_changed_re_renders(): void
    {
        $id = $this->upload(['obj' => [self::ANGLE], 'gltf' => [self::ANGLE]]);
        Queue::fake();

        $this->putJson("/api/products/{$id}", [
            'preview_angles' => [
                'obj' => [self::ANGLE],
                'gltf' => [self::ANGLE, array_merge(self::ANGLE, ['fov' => 30])],
            ],
        ])->assertSuccessful();

        Queue::assertPushed(RenderProductPreviews::class, 1);
        Queue::assertPushed(
            RenderProductPreviews::class,
            fn (RenderProductPreviews $job) => $job->format === 'gltf'
        );
    }

    public function test_the_product_is_only_ready_when_every_format_is(): void
    {
        $product = Product::with('files')->findOrFail(
            $this->upload(['obj' => [self::ANGLE], 'gltf' => [self::ANGLE]])
        );

        $obj = $product->deliverables()->where('format', 'obj')->first();
        $gltf = $product->deliverables()->where('format', 'gltf')->first();

        $obj->withMeta(['render' => ['status' => 'ready', 'error' => null]]);
        $product->refreshPreviewStatus();
        $this->assertSame('rendering', $product->fresh()->preview_status);

        $gltf->withMeta(['render' => ['status' => 'ready', 'error' => null]]);
        $product->refreshPreviewStatus();
        $this->assertSame('ready', $product->fresh()->preview_status);

        $gltf->withMeta(['render' => ['status' => 'failed', 'error' => 'Nothing rendered.']]);
        $product->refreshPreviewStatus();

        $product->refresh();
        $this->assertSame('failed', $product->preview_status);
        $this->assertSame('Nothing rendered.', $product->preview_error);
    }

    // A format uploaded without angles used to drag the whole product back to
    // 'none', hiding the stills the other format had already produced.
    public function test_one_ready_format_is_enough_for_the_product_to_be_ready(): void
    {
        $product = Product::with('files')->findOrFail(
            $this->upload(['obj' => [self::ANGLE], 'gltf' => [self::ANGLE]])
        );

        $product->deliverables()->where('format', 'obj')->first()
            ->withMeta(['render' => ['status' => 'ready', 'error' => null]]);
        $product->deliverables()->where('format', 'gltf')->first()
            ->withMeta(['render' => ['status' => 'none', 'error' => null]]);

        $product->refreshPreviewStatus();

        $this->assertSame('ready', $product->fresh()->preview_status);
    }

    public function test_a_failure_outranks_a_ready_format(): void
    {
        $product = Product::with('files')->findOrFail(
            $this->upload(['obj' => [self::ANGLE], 'gltf' => [self::ANGLE]])
        );

        $product->deliverables()->where('format', 'obj')->first()
            ->withMeta(['render' => ['status' => 'ready', 'error' => null]]);
        $product->deliverables()->where('format', 'gltf')->first()
            ->withMeta(['render' => ['status' => 'failed', 'error' => 'Nothing rendered.']]);

        $product->refreshPreviewStatus();

        $product->refresh();
        $this->assertSame('failed', $product->preview_status);
        $this->assertSame('Nothing rendered.', $product->preview_error);
    }

    public function test_the_payload_groups_previews_by_format(): void
    {
        $id = $this->upload(['obj' => [self::ANGLE], 'gltf' => [self::ANGLE]]);

        $previews = $this->getJson("/api/products/{$id}")->assertSuccessful()->json('previews');

        $this->assertSame(['gltf', 'obj'], array_column($previews, 'format'));
        $this->assertSame(['queued', 'queued'], array_column($previews, 'status'));
        $this->assertSame([[], []], array_column($previews, 'images'));
    }

    public function test_stills_record_which_file_they_came_from(): void
    {
        $product = Product::with('files')->findOrFail($this->upload(['obj' => [self::ANGLE]]));
        $source = $product->deliverables()->first();

        $still = $product->files()->create([
            'source_file_id' => $source->id,
            'kind' => ProductFile::KIND_PREVIEW_IMAGE,
            'format' => 'png',
            'disk' => 'public',
            'path' => 'preview_images/a.png',
            'sort' => 0,
            'bytes' => 10,
            'checksum' => str_repeat('a', 64),
            'meta' => ['source_format' => 'obj'],
        ]);

        $this->assertTrue($source->stills->contains($still));
        $this->assertSame($source->id, $still->source->id);

        $grouped = $this->getJson("/api/products/{$product->id}")->json('previews.0.images');
        $this->assertSame('obj', $grouped[0]['source_format']);
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

    private function upload(array $angles): int
    {
        Sanctum::actingAs($this->seller());

        $files = ['preview_mode' => Product::PREVIEW_ATTESTED_STILLS];

        if (isset($angles['gltf'])) {
            $files['gltfModel'] = UploadedFile::fake()->createWithContent('model.glb', 'glTF binary');
        }

        return $this->postJson('/api/products', $this->payload($files + [
            'preview_angles' => json_encode($angles),
        ]))->assertSuccessful()->json('id');
    }
}
