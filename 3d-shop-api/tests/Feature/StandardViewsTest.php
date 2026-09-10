<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\StandardAngles;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StandardViewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('models');
        Storage::fake('public');
        Sanctum::actingAs(User::create([
            'name' => 'seller',
            'email' => 'seller@example.com',
            'password' => 'password123',
        ]));
    }

    public function test_a_seller_can_publish_without_framing_anything(): void
    {
        $id = $this->publish(['standard_views' => ['obj']]);

        $angles = Product::findOrFail($id)->deliverableFor('obj')->angles();

        $this->assertCount(StandardAngles::COUNT, $angles);
        $this->assertSame('standard', $angles[0]['origin']);
    }

    /** The seller chose their own shots, so those stay first. */
    public function test_their_own_shots_come_before_the_standard_ones(): void
    {
        $mine = ['position' => [1, 2, 3], 'target' => [0, 0, 0], 'fov' => 40];

        $id = $this->publish([
            'preview_angles' => json_encode(['obj' => [$mine]]),
            'standard_views' => ['obj'],
        ]);

        $angles = Product::findOrFail($id)->deliverableFor('obj')->angles();

        $this->assertCount(StandardAngles::COUNT + 1, $angles);
        $this->assertSame([1, 2, 3], $angles[0]['position']);
        $this->assertArrayNotHasKey('origin', $angles[0]);
        $this->assertSame('standard', $angles[1]['origin']);
    }

    /** Opt-in: a seller who frames their own gets nothing extra. */
    public function test_nothing_is_added_when_the_seller_does_not_ask(): void
    {
        $mine = ['position' => [1, 2, 3], 'target' => [0, 0, 0], 'fov' => 40];

        $id = $this->publish(['preview_angles' => json_encode(['obj' => [$mine]])]);

        $this->assertCount(1, Product::findOrFail($id)->deliverableFor('obj')->angles());
    }

    public function test_asking_for_a_format_that_was_not_uploaded_changes_nothing(): void
    {
        $id = $this->publish(['standard_views' => ['obj', 'gltf']]);

        $this->assertNull(Product::findOrFail($id)->deliverableFor('gltf'));
        $this->assertCount(StandardAngles::COUNT, Product::findOrFail($id)->deliverableFor('obj')->angles());
    }

    public function test_attested_stills_still_need_one_or_the_other(): void
    {
        $this->postJson('/api/products', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('preview_angles.obj');

        $this->assertSame(0, Product::count());
    }

    public function test_an_unknown_format_is_refused(): void
    {
        $this->postJson('/api/products', $this->payload(['standard_views' => ['fbx']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('standard_views.0');
    }

    /**
     * The harness fits and centres every model before placing the camera, so
     * these are the same numbers for every product. That is what lets a
     * replaced file be compared against what it replaced, view by view.
     */
    public function test_the_set_is_a_turntable_that_does_not_move(): void
    {
        $angles = StandardAngles::set();

        $this->assertSame(range(0, 7), array_column($angles, 'slot'));
        $this->assertSame($angles, StandardAngles::set());

        foreach ($angles as $angle) {
            $this->assertSame([0.0, 0.0, 0.0], $angle['target']);
            $this->assertEqualsWithDelta(5.0, sqrt(
                $angle['position'][0] ** 2 + $angle['position'][1] ** 2 + $angle['position'][2] ** 2
            ), 0.0001);
            $this->assertGreaterThan(0, $angle['position'][1]);
        }

        // View 1 is a corner, not square-on, because it becomes the thumbnail.
        $this->assertGreaterThan(0.1, abs($angles[0]['position'][0]));
        $this->assertGreaterThan(0.1, abs($angles[0]['position'][2]));

        // The square-on views are still in the set, a quarter turn apart.
        $squareOn = array_filter(
            $angles,
            fn (array $angle) => abs($angle['position'][0]) < 0.0001 || abs($angle['position'][2]) < 0.0001
        );
        $this->assertCount(4, $squareOn);
    }

    public function test_a_thumbnail_can_be_left_to_the_render(): void
    {
        $payload = $this->payload(['standard_views' => ['obj']]);
        unset($payload['thumbnail']);

        $id = $this->postJson('/api/products', $payload)->assertSuccessful()->json('id');

        $this->assertNull(Product::findOrFail($id)->thumbnail());
    }

    public function test_but_only_when_a_render_will_produce_one(): void
    {
        $payload = $this->payload([
            'preview_angles' => json_encode(['obj' => [
                ['position' => [1, 2, 3], 'target' => [0, 0, 0], 'fov' => 40],
            ]]),
        ]);
        unset($payload['thumbnail']);

        $this->postJson('/api/products', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('thumbnail');
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'name' => 'Concept car',
            'price' => '129.00',
            'preview_mode' => Product::PREVIEW_ATTESTED_STILLS,
            'objModel' => UploadedFile::fake()->createWithContent('model.obj', "v 0 0 0\nf 1 1 1\n"),
            'thumbnail' => UploadedFile::fake()->image('thumb.png'),
        ], $extra);
    }

    private function publish(array $extra = []): int
    {
        return $this->postJson('/api/products', $this->payload($extra))
            ->assertSuccessful()
            ->json('id');
    }
}
