<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use App\Support\FormatAgreement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormatAgreementTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'name' => 'Concept car',
            'price_cents' => 12900,
            'user_id' => User::create([
                'name' => 'seller',
                'email' => 'seller@example.com',
                'password' => 'password123',
            ])->id,
        ]);
    }

    public function test_one_format_has_nothing_to_compare(): void
    {
        $this->deliverable('obj', faces: 100, size: [1, 2, 3]);

        $this->assertNull(FormatAgreement::of($this->deliverables()));
    }

    public function test_matching_formats_agree(): void
    {
        $this->deliverable('obj', faces: 213347, size: [2.5423, 1.1489, 4.3574]);
        $this->deliverable('gltf', faces: 213347, size: [2.5423, 1.1489, 4.3574]);

        $agreement = FormatAgreement::of($this->deliverables());

        $this->assertTrue($agreement['agrees']);
        $this->assertSame([], $agreement['differences']);
        $this->assertSame(['gltf', 'obj'], $agreement['compared']);
    }

    /** A clean .glb beside a mangled .fbx shows a buyer whichever tab they open. */
    public function test_a_different_triangle_count_is_reported(): void
    {
        $this->deliverable('gltf', faces: 12000, size: [1, 1, 1]);
        $this->deliverable('fbx', faces: 400000, size: [1, 1, 1]);

        $agreement = FormatAgreement::of($this->deliverables());

        $this->assertFalse($agreement['agrees']);
        $this->assertSame(
            ['Triangle counts differ: .fbx 400,000, .gltf 12,000.'],
            $agreement['differences']
        );
    }

    public function test_a_different_size_is_reported(): void
    {
        $this->deliverable('obj', faces: 500, size: [1, 1, 1]);
        $this->deliverable('gltf', faces: 500, size: [100, 100, 100]);

        $agreement = FormatAgreement::of($this->deliverables());

        $this->assertFalse($agreement['agrees']);
        $this->assertStringStartsWith('Sizes differ:', $agreement['differences'][0]);
    }

    /** Exporters round; a hundredth of a unit is not a different model. */
    public function test_rounding_in_the_bounds_is_not_a_difference(): void
    {
        $this->deliverable('obj', faces: 500, size: [2.5423, 1.1489, 4.3574]);
        $this->deliverable('gltf', faces: 500, size: [2.5431, 1.1485, 4.3578]);

        $this->assertTrue(FormatAgreement::of($this->deliverables())['agrees']);
    }

    /**
     * An .stl repeats a shared corner for every triangle it belongs to, so the
     * honest pair measured off one car disagrees by 477,275 vertices.
     */
    public function test_unwelded_vertices_are_not_a_disagreement(): void
    {
        $this->deliverable('gltf', faces: 213347, size: [2.5423, 1.1489, 4.3574], vertices: 162766);
        $this->deliverable('stl', faces: 213347, size: [2.5423, 1.1489, 4.3574], vertices: 640041);

        $this->assertTrue(FormatAgreement::of($this->deliverables())['agrees']);
    }

    public function test_a_format_that_is_not_measured_yet_is_left_out(): void
    {
        $this->deliverable('obj', faces: 500, size: [1, 1, 1]);
        $unmeasured = $this->deliverable('fbx', faces: 500, size: [1, 1, 1]);
        $unmeasured->update(['meta' => ['facts' => null]]);

        $this->assertNull(FormatAgreement::of($this->deliverables()->fresh()));
    }

    public function test_the_payload_carries_it(): void
    {
        $this->deliverable('obj', faces: 900, size: [1, 1, 1]);
        $this->deliverable('gltf', faces: 901, size: [1, 1, 1]);

        $this->getJson("/api/products/{$this->product->id}")
            ->assertSuccessful()
            ->assertJsonPath('format_agreement.agrees', false)
            // The payload sorts its formats, so the comparison follows.
            ->assertJsonPath('format_agreement.compared', ['gltf', 'obj']);
    }

    private function deliverables()
    {
        return $this->product->fresh(['files'])->deliverables()->get();
    }

    private function deliverable(
        string $format,
        int $faces,
        array $size,
        ?int $vertices = null
    ): ProductFile {
        return $this->product->files()->create([
            'kind' => ProductFile::KIND_DELIVERABLE,
            'format' => $format,
            'disk' => 'models',
            'path' => "models/{$format}",
            'bytes' => 1000,
            'checksum' => hash('sha256', $format),
            'meta' => [
                'sniffed_format' => $format,
                'facts' => [
                    'faces' => $faces,
                    'vertices' => $vertices ?? $faces * 3,
                    'bounds' => ['min' => [0, 0, 0], 'max' => $size, 'size' => $size],
                ],
            ],
        ]);
    }
}
