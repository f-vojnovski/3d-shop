<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The provenance record is only worth something if it can be seen to fail, so
 * each case here breaks a product on purpose and checks that we notice.
 * Nothing here needs the render container: tampering shows up in the hashes
 * before any pixel is produced.
 */
class TamperDetectionTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductFile $model;

    private ProductFile $still;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('models');
        Storage::fake('public');

        $seller = User::create([
            'name' => 'seller',
            'email' => 'seller@example.com',
            'password' => 'password123',
        ]);

        $this->product = Product::create([
            'name' => 'Concept car',
            'price_cents' => 12900,
            'user_id' => $seller->id,
            'preview_mode' => Product::PREVIEW_ATTESTED_STILLS,
        ]);

        $this->model = $this->store(ProductFile::KIND_DELIVERABLE, 'models', 'obj_files/car.obj', "v 0 0 0\n", [
            'sniffed_format' => 'obj',
            'faces' => 1,
            'facts' => ['vertices' => 3, 'faces' => 1],
            'angles' => [['position' => [1, 1, 1], 'target' => [0, 0, 0], 'fov' => 50]],
            'render' => ['status' => 'ready', 'error' => null],
        ]);

        $this->still = $this->store(ProductFile::KIND_PREVIEW_IMAGE, 'public', 'previews/angle-0.png', 'PNG-BYTES', [
            'source_checksum' => $this->model->checksum,
            'source_format' => 'obj',
            'camera' => ['position' => [1, 1, 1], 'target' => [0, 0, 0], 'fov' => 50],
        ], $this->model->id);
    }

    public function test_an_untouched_product_passes_the_audit(): void
    {
        $this->artisan('files:audit', ['--product' => $this->product->id])
            ->expectsOutputToContain('match the checksums recorded for them')
            ->assertSuccessful();
    }

    public function test_swapping_the_stored_model_behind_its_row_is_caught(): void
    {
        // The row is untouched: only the bytes in storage change, which is what
        // a checksum column on its own cannot notice.
        Storage::disk('models')->put($this->model->path, "v 9 9 9\n");

        $this->artisan('files:audit', ['--product' => $this->product->id])
            ->expectsOutputToContain('recorded')
            ->assertFailed();
    }

    public function test_repainting_a_still_is_caught(): void
    {
        Storage::disk('public')->put($this->still->path, 'DIFFERENT-BYTES');

        $this->artisan('files:audit', ['--product' => $this->product->id])->assertFailed();
    }

    public function test_a_file_that_vanished_from_storage_is_caught(): void
    {
        Storage::disk('models')->delete($this->model->path);

        $this->artisan('files:audit', ['--product' => $this->product->id])
            ->expectsOutputToContain('gone from')
            ->assertFailed();
    }

    /**
     * The other kind of substitution: the bytes and the row agree, but the
     * model on sale is no longer the one the stills were rendered from.
     */
    public function test_replacing_the_model_leaves_the_stills_no_longer_on_sale(): void
    {
        $this->getJson("/api/previews/{$this->still->id}/attestation")
            ->assertSuccessful()
            ->assertJsonPath('source_model.still_on_sale', true);

        $this->model->update([
            'path' => 'obj_files/replacement.obj',
            'checksum' => hash('sha256', "v 5 5 5\n"),
        ]);
        Storage::disk('models')->put('obj_files/replacement.obj', "v 5 5 5\n");

        $this->getJson("/api/previews/{$this->still->id}/attestation")
            ->assertSuccessful()
            ->assertJsonPath('source_model.still_on_sale', false);
    }

    public function test_the_record_still_resolves_after_tampering_and_says_what_it_recorded(): void
    {
        Storage::disk('models')->put($this->model->path, "v 9 9 9\n");

        $body = $this->getJson("/api/previews/{$this->still->id}/attestation")
            ->assertSuccessful()
            ->json();

        // Deliberately unchanged: the record is what was true at render time,
        // and files:audit is what compares it to the present.
        $this->assertSame($this->model->checksum, $body['source_model']['sha256']);
        $this->assertSame(3, $body['source_model']['measured']['vertices']);
        $this->assertTrue($body['source_model']['still_on_sale']);
    }

    public function test_the_audit_can_be_narrowed_to_one_product(): void
    {
        $other = Product::create([
            'name' => 'Untouched',
            'price_cents' => 100,
            'user_id' => $this->product->user_id,
        ]);
        $file = $this->store(ProductFile::KIND_DELIVERABLE, 'models', 'obj_files/other.obj', "v 1 1 1\n", []);
        $file->update(['product_id' => $other->id]);
        Storage::disk('models')->put($file->path, 'tampered');

        $this->artisan('files:audit', ['--product' => $this->product->id])->assertSuccessful();
        $this->artisan('files:audit')->assertFailed();
    }

    private function store(
        string $kind,
        string $disk,
        string $path,
        string $contents,
        array $meta,
        ?int $sourceId = null
    ): ProductFile {
        Storage::disk($disk)->put($path, $contents);

        return $this->product->files()->create([
            'kind' => $kind,
            'format' => $kind === ProductFile::KIND_DELIVERABLE ? 'obj' : null,
            'disk' => $disk,
            'path' => $path,
            'sort' => 0,
            'bytes' => strlen($contents),
            'checksum' => hash('sha256', $contents),
            'meta' => $meta === [] ? null : $meta,
            'source_file_id' => $sourceId,
        ]);
    }
}
