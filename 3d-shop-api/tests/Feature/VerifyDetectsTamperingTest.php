<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use App\Support\RenderRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `render:verify` is only worth running if it fails when it should. Matching
 * pixels alone would not: swapping the model and re-recording its checksum
 * leaves every image hash intact.
 */
class VerifyDetectsTamperingTest extends TestCase
{
    use RefreshDatabase;

    private const MODEL = "v 0 0 0\nv 1 0 0\nv 0 1 0\nf 1 2 3\n";

    private const IMAGE = 'the rendered pixels';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('models');
        Storage::fake('public');
    }

    /** The baseline: with nothing touched it passes, so a failure below means something. */
    public function test_an_untouched_product_verifies(): void
    {
        $this->aVerifiableProduct();
        $this->renderReturning(self::IMAGE);

        $this->artisan('render:verify', ['product' => Product::sole()->id])
            ->expectsOutputToContain('reproduces byte-for-byte')
            ->assertExitCode(0);
    }

    /** Someone replaced the stored object and left the record alone. */
    public function test_stored_bytes_that_no_longer_hash_to_the_record_are_caught(): void
    {
        $fixture = $this->aVerifiableProduct();
        $this->renderReturning(self::IMAGE);

        Storage::disk('models')->put($fixture['source']->path, 'a different model entirely');

        $this->artisan('render:verify', ['product' => $fixture['product']->id])
            ->expectsOutputToContain('is not the one that was recorded')
            ->assertExitCode(1);
    }

    /**
     * The subtler one: the model was swapped and its checksum re-recorded, so
     * the cheap check above passes. Only the stills remember what they were
     * drawn from.
     */
    public function test_a_swapped_model_with_a_rewritten_checksum_is_caught(): void
    {
        $fixture = $this->aVerifiableProduct();
        $this->renderReturning(self::IMAGE);

        $swapped = "v 9 9 9\nf 1 1 1\n";
        Storage::disk('models')->put($fixture['source']->path, $swapped);
        $fixture['source']->update(['checksum' => hash('sha256', $swapped)]);

        $this->artisan('render:verify', ['product' => $fixture['product']->id])
            ->expectsOutputToContain('is not the one these stills came from')
            ->assertExitCode(1);
    }

    /** And the plain case: the renderer no longer draws what it drew before. */
    public function test_pixels_that_no_longer_match_are_caught(): void
    {
        $fixture = $this->aVerifiableProduct();
        $this->renderReturning('different pixels now');

        $this->artisan('render:verify', ['product' => $fixture['product']->id])
            ->expectsOutputToContain('MISMATCH')
            ->assertExitCode(1);
    }

    /** Stands in for the container, writing whatever pixels the test asks for. */
    private function renderReturning(string $pixels): void
    {
        $this->app->instance(RenderRunner::class, new class($pixels) extends RenderRunner
        {
            public function __construct(private readonly string $pixels)
            {
                parent::__construct();
            }

            public function run(array $request, string $modelPath, string $scratchDir, ?string $bundleDir = null, array $about = []): array
            {
                $out = $scratchDir.DIRECTORY_SEPARATOR.'out';
                File::ensureDirectoryExists($out, 0775, true);
                File::put($out.DIRECTORY_SEPARATOR.'angle-0.png', $this->pixels);

                return [
                    'status' => 'ok',
                    'images' => [['index' => 0, 'file' => 'angle-0.png', 'coverage' => 0.4]],
                    'blank' => [],
                    'renderer' => ['engine' => 'three.js', 'image' => 'sha256:'.str_repeat('d', 64)],
                ];
            }
        });
    }

    /** @return array{product: Product, source: ProductFile} */
    private function aVerifiableProduct(): array
    {
        $product = Product::create([
            'name' => 'A verifiable listing',
            'price_cents' => 2450,
            'currency' => 'USD',
            'preview_mode' => Product::PREVIEW_ATTESTED_STILLS,
            'user_id' => User::factory()->create()->id,
            'published_at' => now(),
        ]);

        Storage::disk('models')->put('obj_files/model.obj', self::MODEL);

        $source = $product->files()->create([
            'kind' => ProductFile::KIND_DELIVERABLE,
            'format' => 'obj',
            'disk' => 'models',
            'path' => 'obj_files/model.obj',
            'bytes' => strlen(self::MODEL),
            'checksum' => hash('sha256', self::MODEL),
            'meta' => [
                'sniffed_format' => 'obj',
                'faces' => 1,
                'angles' => [['position' => [3, 2, 4], 'target' => [0, 0, 0], 'fov' => 75]],
            ],
        ]);

        $product->files()->create([
            'source_file_id' => $source->id,
            'kind' => ProductFile::KIND_PREVIEW_IMAGE,
            'format' => 'png',
            'disk' => 'public',
            'path' => 'preview_images/angle-0.png',
            'sort' => 0,
            'bytes' => strlen(self::IMAGE),
            'checksum' => hash('sha256', self::IMAGE),
            'meta' => [
                'camera' => ['position' => [3, 2, 4], 'target' => [0, 0, 0], 'fov' => 75],
                'source_checksum' => hash('sha256', self::MODEL),
            ],
        ]);

        return ['product' => $product->refresh()->load('files'), 'source' => $source];
    }
}
