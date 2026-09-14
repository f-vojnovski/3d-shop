<?php

namespace Tests\Feature;

use App\Jobs\BuildViewerProxy;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use App\Support\ModelConverter;
use App\Support\ProxyRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The proxy is what a buyer is allowed to have. A simplifier that returned the
 * model untouched would put the full mesh on the viewer's route, and the seller
 * would have no way of knowing.
 */
class ProxyIsCutDownTest extends TestCase
{
    use RefreshDatabase;

    private ProductFile $source;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('models');
        Storage::fake('public');

        $this->source = $this->aModelWantingAProxy();
    }

    public function test_a_simplifier_that_returned_everything_publishes_nothing(): void
    {
        $this->simplifierReturning($this->cubeOf(12));

        (new BuildViewerProxy($this->source->id))->handle(app(ProxyRunner::class), app(ModelConverter::class));

        $this->assertNull($this->source->refresh()->proxy(), 'An undecimated model was published as the proxy.');
    }

    public function test_a_genuinely_cut_down_proxy_is_published(): void
    {
        $this->simplifierReturning($this->cubeOf(2));

        (new BuildViewerProxy($this->source->id))->handle(app(ProxyRunner::class), app(ModelConverter::class));

        $proxy = $this->source->refresh()->proxy();

        $this->assertNotNull($proxy);
        $this->assertSame(2, $proxy->meta['faces'], 'The stored count should be the one measured, not the one reported.');
    }

    /** Measured here, so a result file claiming a small number changes nothing. */
    public function test_the_count_comes_from_the_file_not_the_result(): void
    {
        $this->simplifierReturning($this->cubeOf(12), ['before' => 12, 'after' => 1]);

        (new BuildViewerProxy($this->source->id))->handle(app(ProxyRunner::class), app(ModelConverter::class));

        $this->assertNull(
            $this->source->refresh()->proxy(),
            'The simplifier claimed it decimated and was believed.'
        );
    }

    /** @param array{before: int, after: int}|null $claim */
    private function simplifierReturning(string $glb, ?array $claim = null): void
    {
        $this->app->instance(ProxyRunner::class, new class($glb, $claim) extends ProxyRunner
        {
            public function __construct(private readonly string $glb, private readonly ?array $claim)
            {
                parent::__construct();
            }

            public function run(float $ratio, string $method, string $format, string $modelPath, string $scratchDir, ?string $bundleDir = null, ?string $entry = null, array $about = []): array
            {
                $out = $scratchDir.DIRECTORY_SEPARATOR.'out';
                File::ensureDirectoryExists($out, 0775, true);
                File::put($out.DIRECTORY_SEPARATOR.'proxy.glb', $this->glb);

                return [
                    'status' => 'ok',
                    'file' => 'proxy.glb',
                    'bytes' => strlen($this->glb),
                    'triangles' => $this->claim ?? ['before' => 12, 'after' => 12],
                ];
            }
        });
    }

    /** A glb of `$faces` triangles, so MeshFacts has something real to count. */
    private function cubeOf(int $faces): string
    {
        $positions = '';
        $indices = '';

        for ($i = 0; $i < $faces; $i++) {
            $positions .= pack('g9', 0, 0, 0, 1, 0, 0, 0, 1, 0);
            $indices .= pack('v3', $i * 3, $i * 3 + 1, $i * 3 + 2);
        }

        $binary = $positions.$indices;
        $binary .= str_repeat("\0", (4 - (strlen($binary) % 4)) % 4);

        $json = json_encode([
            'asset' => ['version' => '2.0'],
            'scene' => 0,
            'scenes' => [['nodes' => [0]]],
            'nodes' => [['mesh' => 0]],
            'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0], 'indices' => 1]]]],
            'accessors' => [
                ['bufferView' => 0, 'componentType' => 5126, 'count' => $faces * 3, 'type' => 'VEC3', 'min' => [0, 0, 0], 'max' => [1, 1, 0]],
                ['bufferView' => 1, 'componentType' => 5123, 'count' => $faces * 3, 'type' => 'SCALAR'],
            ],
            'bufferViews' => [
                ['buffer' => 0, 'byteOffset' => 0, 'byteLength' => strlen($positions)],
                ['buffer' => 0, 'byteOffset' => strlen($positions), 'byteLength' => strlen($indices)],
            ],
            'buffers' => [['byteLength' => strlen($binary)]],
        ]);

        $json .= str_repeat(' ', (4 - (strlen($json) % 4)) % 4);

        return 'glTF'.pack('V', 2).pack('V', 12 + 8 + strlen($json) + 8 + strlen($binary))
            .pack('V', strlen($json)).'JSON'.$json
            .pack('V', strlen($binary)).'BIN'."\0".$binary;
    }

    private function aModelWantingAProxy(): ProductFile
    {
        $product = Product::create([
            'name' => 'A model with a viewer copy',
            'price_cents' => 3000,
            'currency' => 'USD',
            'user_id' => User::factory()->create()->id,
            'published_at' => now(),
        ]);

        $glb = $this->cubeOf(12);
        Storage::disk('models')->put('gltf_files/model.glb', $glb);

        return $product->files()->create([
            'kind' => ProductFile::KIND_DELIVERABLE,
            'format' => 'gltf',
            'disk' => 'models',
            'path' => 'gltf_files/model.glb',
            'bytes' => strlen($glb),
            'checksum' => hash('sha256', $glb),
            'meta' => [
                'sniffed_format' => 'glb',
                'faces' => 12,
                'facts' => ['faces' => 12],
                'proxy' => ['mode' => 'model', 'ratio' => 0.1, 'method' => 'careful'],
            ],
        ]);
    }
}
