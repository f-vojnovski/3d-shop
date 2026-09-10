<?php

namespace Tests\Feature;

use App\Jobs\RenderProductPreviews;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use App\Events\PreviewRenderFinished;
use App\Support\RenderRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class RenderRetryTest extends TestCase
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
        Event::fake([PreviewRenderFinished::class]);
        Storage::fake('models');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/render-scratch'));

        parent::tearDown();
    }

    public function test_a_retryable_failure_is_thrown_so_the_queue_retries(): void
    {
        $product = $this->renderableProduct();

        $this->expectException(RuntimeException::class);

        try {
            $this->runJob($product, ['retryable' => true], attempt: 1);
        } finally {
            // Still 'rendering': the attempt is not over, so the seller sees no flap.
            $this->assertSame('rendering', $product->fresh()->preview_status);
        }
    }

    public function test_the_last_attempt_records_the_reason_instead_of_retrying(): void
    {
        $product = $this->renderableProduct();

        $this->runJob($product, ['retryable' => true], attempt: 2);

        $product->refresh();
        $this->assertSame('failed', $product->preview_status);
        $this->assertSame('Rendering timed out.', $product->preview_error);
    }

    public function test_a_permanent_failure_does_not_burn_a_second_attempt(): void
    {
        $product = $this->renderableProduct();

        $this->runJob($product, [
            'retryable' => false,
            'reason' => 'Every rendered image was blank.',
        ], attempt: 1);

        $product->refresh();
        $this->assertSame('failed', $product->preview_status);
        $this->assertSame('Every rendered image was blank.', $product->preview_error);
    }

    // A format that is no longer attached means the seller removed that file,
    // so the job is stale rather than failed.
    public function test_a_job_for_a_removed_format_does_nothing(): void
    {
        $product = $this->product();
        $runner = $this->runner(['retryable' => true]);

        (new RenderProductPreviews($product->id, 'obj'))->handle($runner);

        $this->assertSame(0, $runner->calls);
        $this->assertSame('none', $product->fresh()->preview_status);
    }

    public function test_exhausting_the_attempts_leaves_a_readable_status(): void
    {
        $product = $this->renderableProduct();

        (new RenderProductPreviews($product->id, 'obj'))->failed(new RuntimeException('worker died'));

        $product->refresh();
        $this->assertSame('failed', $product->preview_status);
        $this->assertNotNull($product->preview_error);
    }

    /**
     * The two are configured in different files, so nothing else catches a
     * change that lets Redis re-release a render while it is still running.
     */
    public function test_the_queue_waits_longer_than_a_render_may_take(): void
    {
        $job = new RenderProductPreviews(1, 'obj');

        $this->assertGreaterThan($job->timeout, config('queue.connections.redis.retry_after'));
        $this->assertGreaterThan($job->timeout, $job->uniqueFor);
    }

    private function runJob(Product $product, array $result, int $attempt): void
    {
        $job = new RenderProductPreviews($product->id, 'obj');
        $job->job = tap(new FakeJob(), fn (FakeJob $fake) => $fake->attempts = $attempt);

        $job->handle($this->runner($result));
    }

    private function runner(array $result): RenderRunner
    {
        return new class(array_merge([
            'status' => 'failed',
            'reason' => 'Rendering timed out.',
        ], $result)) extends RenderRunner
        {
            public int $calls = 0;

            public function __construct(private array $result)
            {
                parent::__construct();
            }

            public function run(array $request, string $modelPath, string $scratchDir): array
            {
                $this->calls++;

                return $this->result;
            }
        };
    }

    private function product(): Product
    {
        $user = User::create([
            'name' => 'seller',
            'email' => 'seller@example.com',
            'password' => 'password123',
        ]);

        return Product::create([
            'name' => 'Half-track',
            'price_cents' => 2450,
            'preview_mode' => Product::PREVIEW_ATTESTED_STILLS,
            'user_id' => $user->id,
        ]);
    }

    private function renderableProduct(): Product
    {
        $product = $this->product();

        Storage::disk('models')->put('obj_files/model.obj', "v 0 0 0\nf 1 1 1\n");

        $product->files()->create([
            'kind' => ProductFile::KIND_DELIVERABLE,
            'format' => 'obj',
            'disk' => 'models',
            'path' => 'obj_files/model.obj',
            'bytes' => 18,
            'checksum' => str_repeat('a', 64),
            'meta' => [
                'sniffed_format' => 'obj',
                'triangles' => 1,
                'angles' => [self::ANGLE],
                'render' => ['status' => 'queued', 'error' => null],
            ],
        ]);

        return $product;
    }

    public function test_giving_up_tells_the_seller(): void
    {
        $product = $this->renderableProduct();

        $this->runJob($product, [
            'retryable' => false,
            'reason' => 'Every rendered image was blank.',
        ], attempt: 1);

        Event::assertDispatched(
            PreviewRenderFinished::class,
            fn (PreviewRenderFinished $event) => $event->productId === $product->id
                && $event->sellerId === $product->user_id
                && $event->status === 'failed'
                && $event->error === 'Every rendered image was blank.'
        );
    }

    public function test_a_retry_says_nothing_until_it_is_settled(): void
    {
        $product = $this->renderableProduct();

        try {
            $this->runJob($product, ['retryable' => true], attempt: 1);
        } catch (RuntimeException) {
            // The throw is how the queue is told to retry.
        }

        Event::assertNotDispatched(PreviewRenderFinished::class);
    }

    public function test_exhausting_the_attempts_tells_the_seller(): void
    {
        $product = $this->renderableProduct();

        (new RenderProductPreviews($product->id, 'obj'))->failed(new RuntimeException('worker died'));

        Event::assertDispatched(
            PreviewRenderFinished::class,
            fn (PreviewRenderFinished $event) => $event->productId === $product->id
                && $event->status === 'failed'
        );
    }

    // The seller can edit angles while a render is in flight; the older run
    // must not land its stills on top of the newer one's.
    public function test_a_run_whose_angles_were_superseded_writes_nothing(): void
    {
        $product = $this->renderableProduct();
        $source = $product->deliverables()->first();

        $runner = new class extends RenderRunner
        {
            public ProductFile $source;

            public function __construct()
            {
                parent::__construct();
            }

            public function run(array $request, string $modelPath, string $scratchDir): array
            {
                // Stands in for the seller saving new angles mid-render.
                $this->source->withMeta(['angles' => [
                    ['position' => [1, 1, 1], 'target' => [0, 0, 0], 'fov' => 60],
                    ['position' => [2, 2, 2], 'target' => [0, 0, 0], 'fov' => 60],
                ]]);

                return [
                    'status' => 'ok',
                    'images' => [['file' => 'angle-0.png', 'index' => 0, 'coverage' => 0.3]],
                    'blank' => [],
                    'renderer' => ['engine' => 'three.js'],
                ];
            }
        };

        $runner->source = $source;

        (new RenderProductPreviews($product->id, 'obj'))->handle($runner);

        $this->assertSame(0, $source->fresh()->stills()->count());
        $this->assertSame('rendering', $product->fresh()->preview_status);
        $this->assertCount(2, $source->fresh()->angles());
    }
}
