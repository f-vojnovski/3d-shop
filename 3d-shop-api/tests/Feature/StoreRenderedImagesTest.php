<?php

namespace Tests\Feature;

use App\Jobs\RenderProductPreviews;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use App\Support\RenderRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreRenderedImagesTest extends TestCase
{
    use RefreshDatabase;

    private const ANGLES = [
        ['position' => [3, 2, 4], 'target' => [0, 0, 0], 'fov' => 75],
        ['position' => [-3, 1, -4], 'target' => [0, 0, 0], 'fov' => 50],
    ];

    private const RENDERER = [
        'engine' => 'three.js',
        'three' => '0.186.0',
        'browser' => 'Chromium 152.0.7977.82',
        'rasterizer' => 'swiftshader',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Storage::fake('models');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/render-scratch'));

        parent::tearDown();
    }

    public function test_each_rendered_image_becomes_a_still_with_its_own_record(): void
    {
        $source = $this->deliverable();

        $this->render($source, ['angle-0.png' => 'first bytes', 'angle-1.png' => 'second bytes']);

        $stills = $source->stills()->get();
        $this->assertCount(2, $stills);

        $first = $stills->firstWhere('sort', 0);
        $this->assertSame('png', $first->format);
        $this->assertSame('public', $first->disk);
        $this->assertSame($source->id, $first->source_file_id);
        $this->assertSame(hash('sha256', 'first bytes'), $first->checksum);
        $this->assertSame(strlen('first bytes'), $first->bytes);
        Storage::disk('public')->assertExists($first->path);

        $this->assertSame(75, $first->meta['camera']['fov']);
        $this->assertSame(50, $stills->firstWhere('sort', 1)->meta['camera']['fov']);
        $this->assertSame('obj', $first->meta['source_format']);
        $this->assertSame($source->checksum, $first->meta['source_checksum']);
        $this->assertSame(self::RENDERER, $first->meta['renderer']);
        $this->assertSame(0.42, $first->meta['coverage']);
    }

    public function test_re_rendering_replaces_that_format_s_stills(): void
    {
        $source = $this->deliverable();
        $this->render($source, ['angle-0.png' => 'first bytes', 'angle-1.png' => 'second bytes']);
        $before = $source->stills()->pluck('id');

        $this->render($source, ['angle-0.png' => 'redone']);

        $after = $source->stills()->get();
        $this->assertCount(1, $after);
        $this->assertTrue($before->doesntContain($after->first()->id));
        $this->assertSame(hash('sha256', 'redone'), $after->first()->checksum);
    }

    public function test_it_leaves_another_format_s_stills_alone(): void
    {
        $obj = $this->deliverable();
        $gltf = $this->deliverable('gltf', 'gltf_files/model.glb');
        $this->render($gltf, ['angle-0.png' => 'gltf bytes']);

        $this->render($obj, ['angle-0.png' => 'obj bytes']);

        $this->assertCount(1, $gltf->stills()->get());
        $this->assertSame(hash('sha256', 'gltf bytes'), $gltf->stills()->first()->checksum);
    }

    // The container parses untrusted geometry, so its result.json is input.
    public function test_a_file_the_renderer_should_not_have_named_is_ignored(): void
    {
        $source = $this->deliverable();

        $this->render(
            $source,
            ['angle-0.png' => 'kept'],
            [
                ['file' => 'angle-0.png', 'index' => 0, 'coverage' => 0.42],
                ['file' => '../../../../secret.png', 'index' => 1, 'coverage' => 0.42],
                ['file' => 'angle-2.png', 'index' => 'two', 'coverage' => 0.42],
            ]
        );

        $stills = $source->stills()->get();
        $this->assertCount(1, $stills);
        $this->assertSame(0, $stills->first()->sort);
    }

    public function test_blank_angles_are_reported_to_the_seller(): void
    {
        $source = $this->deliverable();

        $this->render($source, ['angle-0.png' => 'only one'], null, blank: [1]);

        $this->assertSame(
            '1 of 2 angles rendered blank and were discarded.',
            $source->fresh()->renderError()
        );
        $this->assertSame('ready', $source->fresh()->renderStatus());
    }

    private function render(
        ProductFile $source,
        array $files,
        ?array $images = null,
        array $blank = []
    ): void {
        $runner = new class($files, $images ?? $this->describe($files), $blank) extends RenderRunner
        {
            public function __construct(
                private array $files,
                private array $images,
                private array $blank
            ) {
                parent::__construct();
            }

            public function run(array $request, string $modelPath, string $scratchDir): array
            {
                $out = $scratchDir.DIRECTORY_SEPARATOR.'out';
                File::makeDirectory($out, 0775, true, true);

                foreach ($this->files as $name => $contents) {
                    file_put_contents($out.DIRECTORY_SEPARATOR.$name, $contents);
                }

                return [
                    'status' => 'ok',
                    'images' => $this->images,
                    'blank' => $this->blank,
                    'renderer' => StoreRenderedImagesTest::renderer(),
                ];
            }
        };

        (new RenderProductPreviews($source->product_id, $source->format))->handle($runner);
    }

    public static function renderer(): array
    {
        return self::RENDERER;
    }

    private function describe(array $files): array
    {
        $images = [];
        $index = 0;

        foreach (array_keys($files) as $name) {
            $images[] = ['file' => $name, 'index' => $index++, 'coverage' => 0.42];
        }

        return $images;
    }

    private function deliverable(string $format = 'obj', string $path = 'obj_files/model.obj'): ProductFile
    {
        $product = Product::firstOrCreate(
            ['name' => 'Half-track'],
            [
                'price_cents' => 2450,
                'preview_mode' => Product::PREVIEW_ATTESTED_STILLS,
                'user_id' => User::firstOrCreate(
                    ['name' => 'seller'],
                    ['email' => 'seller@example.com', 'password' => 'password123']
                )->id,
            ]
        );

        Storage::disk('models')->put($path, 'model bytes');

        return $product->files()->create([
            'kind' => ProductFile::KIND_DELIVERABLE,
            'format' => $format,
            'disk' => 'models',
            'path' => $path,
            'bytes' => 11,
            'checksum' => hash('sha256', $format.' model'),
            'meta' => [
                'sniffed_format' => $format,
                'faces' => 10,
                'angles' => self::ANGLES,
                'render' => ['status' => 'queued', 'error' => null],
            ],
        ]);
    }
}
