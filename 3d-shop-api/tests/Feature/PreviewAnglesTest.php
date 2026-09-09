<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
        $this->postJson('/api/products', $this->payload([
            'preview_mode' => Product::PREVIEW_ATTESTED_STILLS,
            'preview_angles' => json_encode([self::ANGLE]),
        ]))->assertSuccessful();

        $angles = Product::sole()->preview_angles;

        $this->assertCount(1, $angles);
        $this->assertSame([3.2, 1.8, 4.1], $angles[0]['position']);
        $this->assertSame(75, $angles[0]['fov']);

        $this->assertSame(
            [3.2, 1.8, 4.1],
            $this->getJson('/api/products/1')->json('preview_angles.0.position')
        );
    }

    public function test_a_product_defaults_to_no_angles(): void
    {
        $this->seller();

        $this->postJson('/api/products', $this->payload())->assertSuccessful();

        $this->assertSame([], $this->getJson('/api/products/1')->json('preview_angles'));
    }

    public function test_a_malformed_angle_is_rejected(): void
    {
        $this->seller();

        $this->postJson('/api/products', $this->payload([
            'preview_angles' => json_encode([['position' => [1, 2], 'target' => [0, 0, 0], 'fov' => 75]]),
        ]))->assertStatus(422)->assertJsonValidationErrors('preview_angles.0.position');
    }

    public function test_a_nonsense_field_of_view_is_rejected(): void
    {
        $this->seller();

        $this->postJson('/api/products', $this->payload([
            'preview_angles' => json_encode([array_merge(self::ANGLE, ['fov' => 400])]),
        ]))->assertStatus(422)->assertJsonValidationErrors('preview_angles.0.fov');
    }

    public function test_too_many_angles_are_rejected(): void
    {
        $this->seller();

        $this->postJson('/api/products', $this->payload([
            'preview_angles' => json_encode(array_fill(0, 9, self::ANGLE)),
        ]))->assertStatus(422)->assertJsonValidationErrors('preview_angles');
    }

    public function test_the_owner_can_replace_the_angles_later(): void
    {
        $this->seller();
        $this->postJson('/api/products', $this->payload())->assertSuccessful();

        $this->putJson('/api/products/1', [
            'preview_mode' => Product::PREVIEW_ATTESTED_STILLS,
            'preview_angles' => [self::ANGLE, self::ANGLE],
        ])->assertSuccessful();

        $this->assertCount(2, Product::sole()->preview_angles);
    }
}
