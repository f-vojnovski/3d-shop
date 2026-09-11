<?php

namespace Tests\Feature;

use App\Jobs\RenderProductPreviews;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use App\Support\ModelConverter;
use App\Support\RenderRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * A format PHP cannot parse is converted to glb, and the images come from the
 * conversion while the buyer still downloads what they uploaded. The record has
 * to name both files and the tool between them.
 */
class ConvertedFormatTest extends TestCase
{
    use RefreshDatabase;

    private ProductFile $source;

    private array $seenRequest = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Storage::fake('models');
        Storage::fake('public');

        $product = Product::create([
            'name' => 'Watch tower',
            'price_cents' => 4500,
            'preview_mode' => Product::PREVIEW_ATTESTED_STILLS,
            'user_id' => User::create([
                'name' => 'seller',
                'email' => 'seller@example.com',
                'password' => 'password123',
            ])->id,
        ]);

        Storage::disk('models')->put('fbx_files/tower.fbx', 'fbx bytes');

        $this->source = $product->files()->create([
            'kind' => ProductFile::KIND_DELIVERABLE,
            'format' => 'fbx',
            'disk' => 'models',
            'path' => 'fbx_files/tower.fbx',
            'bytes' => 9,
            'checksum' => hash('sha256', 'fbx bytes'),
            'meta' => [
                'sniffed_format' => 'fbx',
                'faces' => 0,
                'angles' => [['position' => [3, 2, 4], 'target' => [0, 0, 0], 'fov' => 75]],
                'render' => ['status' => 'queued', 'error' => null],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/render-scratch'));

        parent::tearDown();
    }

    public function test_the_file_the_renderer_opened_is_kept_and_measured(): void
    {
        $this->render();

        $derived = $this->source->derived()->first();

        $this->assertNotNull($derived, 'The conversion should have been stored.');
        $this->assertSame('glb', $derived->format);
        $this->assertSame(ProductFile::KIND_DERIVED, $derived->kind);
        $this->assertSame($this->source->id, $derived->source_file_id);
        Storage::disk('models')->assertExists($derived->path);

        // Measured from the conversion, because the upload could not be read.
        $facts = $this->source->fresh()->facts();
        $this->assertSame(1, $facts['faces']);
        $this->assertSame(3, $facts['vertices']);
    }

    public function test_the_record_names_both_files_and_the_tool_between_them(): void
    {
        $this->render();

        $source = $this->source->fresh();
        $derived = $source->derived()->first();

        $this->assertSame('assimp 9.9 (test)', $source->meta['conversion']['tool']);
        $this->assertSame('glb', $source->meta['conversion']['to']);
        $this->assertSame($derived->checksum, $source->meta['conversion']['derived_sha256']);
        $this->assertSame($source->checksum, $derived->meta['converted_from']);
    }

    /** The harness picks its loader from this, so it must name the conversion. */
    public function test_the_renderer_is_told_it_is_opening_a_glb(): void
    {
        $this->render();

        $this->assertSame('glb', $this->seenRequest['source']['format']);
    }

    public function test_the_buyer_still_downloads_the_format_they_bought(): void
    {
        $this->render();

        $product = Product::with('files')->find($this->source->product_id);

        $this->assertSame(['fbx'], $product->availableFormats());
        $this->assertSame('fbx', $product->deliverableFor('fbx')->format);
    }

    public function test_re_rendering_replaces_the_conversion(): void
    {
        $this->render();
        $before = $this->source->derived()->pluck('id');

        $this->render();

        $after = $this->source->derived()->get();
        $this->assertCount(1, $after);
        $this->assertTrue($before->doesntContain($after->first()->id));
    }

    public function test_a_conversion_that_fails_stops_the_render(): void
    {
        $this->render(converts: false);

        $this->assertCount(0, $this->source->derived()->get());
        $this->assertCount(0, $this->source->stills()->get());
        $this->assertSame('failed', $this->source->fresh()->renderStatus());
        $this->assertSame(
            'That file could not be converted.',
            $this->source->fresh()->renderError()
        );
    }

    /** assimp names a file it refuses and exits non-zero; a crash says nothing. */
    public function test_a_converter_that_crashed_is_retried(): void
    {
        $this->expectException(RuntimeException::class);

        try {
            $this->render(converts: false, retryable: true);
        } finally {
            // Left rendering: the attempt is not over, so the seller sees no flap.
            $this->assertSame('rendering', $this->source->fresh()->renderStatus());
            $this->assertCount(0, $this->source->derived()->get());
        }
    }

    private function render(bool $converts = true, bool $retryable = false): void
    {
        $glb = $this->glb();
        $test = $this;

        $converter = new class($glb, $converts, $retryable) extends ModelConverter
        {
            public function __construct(
                private string $glb,
                private bool $converts,
                private bool $retryable
            ) {
                parent::__construct();
            }

            public function toGlb(string $sourcePath, string $scratchDir): array
            {
                if (! $this->converts) {
                    return [
                        'status' => 'failed',
                        'reason' => 'That file could not be converted.',
                        'retryable' => $this->retryable,
                    ];
                }

                $target = $scratchDir.DIRECTORY_SEPARATOR.'converted.glb';
                File::copy($this->glb, $target);

                return ['status' => 'ok', 'path' => $target, 'tool' => 'assimp 9.9 (test)'];
            }
        };

        $runner = new class($test) extends RenderRunner
        {
            public function __construct(private ConvertedFormatTest $test)
            {
                parent::__construct();
            }

            public function run(array $request, string $modelPath, string $scratchDir, ?string $bundleDir = null): array
            {
                $this->test->remember($request);

                $out = $scratchDir.DIRECTORY_SEPARATOR.'out';
                File::makeDirectory($out, 0775, true, true);
                File::put($out.DIRECTORY_SEPARATOR.'angle-0.png', 'shaded bytes');

                return [
                    'status' => 'ok',
                    'images' => [['index' => 0, 'file' => 'angle-0.png', 'coverage' => 0.4]],
                    'blank' => [],
                    'renderer' => ['engine' => 'three.js'],
                ];
            }
        };

        (new RenderProductPreviews($this->source->product_id, 'fbx'))->handle($runner, $converter);
    }

    public function remember(array $request): void
    {
        $this->seenRequest = $request;
    }

    /** A minimal but valid glb: one triangle, one material. */
    private function glb(): string
    {
        $positions = pack('g9', 0, 0, 0, 1, 0, 0, 0, 2, 0);

        $gltf = json_encode([
            'asset' => ['version' => '2.0'],
            'scene' => 0,
            'scenes' => [['nodes' => [0]]],
            'nodes' => [['mesh' => 0]],
            'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0], 'mode' => 4]]]],
            'accessors' => [[
                'bufferView' => 0,
                'componentType' => 5126,
                'count' => 3,
                'type' => 'VEC3',
                'min' => [0, 0, 0],
                'max' => [1, 2, 0],
            ]],
            'bufferViews' => [['buffer' => 0, 'byteOffset' => 0, 'byteLength' => strlen($positions)]],
            'buffers' => [['byteLength' => strlen($positions)]],
            'materials' => [[]],
        ]);

        $json = $gltf.str_repeat(' ', (4 - (strlen($gltf) % 4)) % 4);
        $bin = $positions.str_repeat("\0", (4 - (strlen($positions) % 4)) % 4);
        $body = pack('VV', strlen($json), 0x4e4f534a).$json
            .pack('VV', strlen($bin), 0x004e4942).$bin;

        $directory = storage_path('framework/testing/converted');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/source.glb';
        File::put($path, 'glTF'.pack('VV', 2, 12 + strlen($body)).$body);

        return $path;
    }
}
