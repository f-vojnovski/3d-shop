<?php

namespace Tests\Feature;

use App\Jobs\RenderProductPreviews;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use App\Support\RenderRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\FakeJob;
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

    public function test_a_product_with_no_model_file_fails_without_running_the_renderer(): void
    {
        $product = $this->product();
        $runner = $this->runner(['retryable' => true]);

        (new RenderProductPreviews($product->id))->handle($runner);

        $this->assertSame(0, $runner->calls);
        $this->assertSame('failed', $product->fresh()->preview_status);
    }

    public function test_exhausting_the_attempts_leaves_a_readable_status(): void
    {
        $product = $this->renderableProduct();

        (new RenderProductPreviews($product->id))->failed(new RuntimeException('worker died'));

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
        $job = new RenderProductPreviews(1);

        $this->assertGreaterThan($job->timeout, config('queue.connections.redis.retry_after'));
        $this->assertGreaterThan($job->timeout, $job->uniqueFor);
    }

    private function runJob(Product $product, array $result, int $attempt): void
    {
        $job = new RenderProductPreviews($product->id);
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
            'preview_angles' => [self::ANGLE],
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
            'meta' => ['sniffed_format' => 'obj', 'triangles' => 1],
        ]);

        return $product;
    }
}
