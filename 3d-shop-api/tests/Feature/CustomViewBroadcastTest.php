<?php

namespace Tests\Feature;

use App\Events\CustomViewDrawn;
use App\Jobs\RenderCustomView;
use App\Models\CustomView;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use App\Support\RenderRunner;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The viewer hears about their own view over Reverb, not by polling for it. */
class CustomViewBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private CustomView $view;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('models');
        Storage::fake('public');

        $seller = User::create(['name' => 'seller', 'email' => 's@example.com', 'password' => 'password123']);
        $this->viewer = User::create(['name' => 'viewer', 'email' => 'v@example.com', 'password' => 'password123']);

        $product = Product::create([
            'name' => 'Truck',
            'price_cents' => 1000,
            'preview_mode' => Product::PREVIEW_ATTESTED_STILLS,
            'user_id' => $seller->id,
        ]);

        Storage::disk('models')->put('gltf_files/truck.glb', 'bytes');

        $source = $product->files()->create([
            'kind' => ProductFile::KIND_DELIVERABLE,
            'format' => 'gltf',
            'disk' => 'models',
            'path' => 'gltf_files/truck.glb',
            'bytes' => 5,
            'checksum' => hash('sha256', 'bytes'),
            'meta' => ['sniffed_format' => 'glb'],
        ]);

        $camera = ['position' => [0, 5, 0.01], 'target' => [0, 0, 0], 'up' => [0, 1, 0], 'fov' => 75];

        $this->view = CustomView::create([
            'user_id' => $this->viewer->id,
            'product_id' => $product->id,
            'product_file_id' => $source->id,
            'pass' => 'wireframe',
            'status' => CustomView::QUEUED,
            'camera' => $camera,
            'fingerprint' => CustomView::fingerprintOf($camera, 'wireframe'),
            'expires_at' => now()->addHours(2),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/custom-views'));

        parent::tearDown();
    }

    public function test_a_drawn_view_is_announced_to_the_viewer_alone(): void
    {
        Event::fake([CustomViewDrawn::class]);

        $this->render(ok: true);

        Event::assertDispatched(CustomViewDrawn::class, function (CustomViewDrawn $event) {
            $channels = array_map(fn ($channel) => (string) $channel, $event->broadcastOn());

            return $event->viewerId === $this->viewer->id
                && $event->viewId === $this->view->id
                && $event->status === CustomView::READY
                && $event->pass === 'wireframe'
                && $event->url !== null
                && $channels === [(string) new PrivateChannel('viewers.'.$this->viewer->id)];
        });
    }

    public function test_a_failed_view_is_announced_too(): void
    {
        Event::fake([CustomViewDrawn::class]);

        $this->render(ok: false);

        Event::assertDispatched(CustomViewDrawn::class, fn (CustomViewDrawn $event) => $event->status === CustomView::FAILED
            && $event->error !== null
            && $event->url === null);
    }

    public function test_the_renderer_is_asked_for_one_camera_and_one_pass(): void
    {
        Event::fake([CustomViewDrawn::class]);

        $this->render(ok: true);

        $this->assertSame([$this->view->camera], $this->seen['angles']);
        $this->assertSame(['wireframe'], $this->seen['passes']);
    }

    private array $seen = [];

    private function render(bool $ok): void
    {
        $test = $this;

        $runner = new class($test, $ok) extends RenderRunner
        {
            public function __construct(private CustomViewBroadcastTest $test, private bool $ok)
            {
                parent::__construct();
            }

            public function run(array $request, string $modelPath, string $scratchDir, ?string $bundleDir = null): array
            {
                $this->test->remember($request);

                if (! $this->ok) {
                    return ['status' => 'failed', 'reason' => 'Every rendered image was blank.'];
                }

                $out = $scratchDir.DIRECTORY_SEPARATOR.'out';
                File::makeDirectory($out, 0775, true, true);
                File::put($out.DIRECTORY_SEPARATOR.'wireframe-0.png', 'lines');

                return [
                    'status' => 'ok',
                    'images' => [['index' => 0, 'file' => 'wireframe-0.png', 'pass' => 'wireframe', 'coverage' => 0.3]],
                    'blank' => [],
                ];
            }
        };

        (new RenderCustomView($this->view->id))->handle($runner);
    }

    public function remember(array $request): void
    {
        $this->seen = $request;
    }
}
