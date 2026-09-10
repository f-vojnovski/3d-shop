<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReapStalledRendersTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_render_stuck_for_too_long_is_failed_with_a_readable_reason(): void
    {
        $file = $this->deliverable('rendering', stalledMinutes: 90);

        $this->artisan('renders:reap')->assertSuccessful();

        $file->refresh();
        $this->assertSame('failed', $file->renderStatus());
        $this->assertStringContainsString('stopped without reporting', $file->renderError());
        $this->assertSame('failed', $file->product->fresh()->preview_status);
    }

    public function test_a_render_that_only_just_started_is_left_alone(): void
    {
        $file = $this->deliverable('rendering', stalledMinutes: 2);

        $this->artisan('renders:reap')->assertSuccessful();

        $this->assertSame('rendering', $file->refresh()->renderStatus());
    }

    public function test_a_finished_render_is_never_touched(): void
    {
        $file = $this->deliverable('ready', stalledMinutes: 500);

        $this->artisan('renders:reap')->assertSuccessful();

        $this->assertSame('ready', $file->refresh()->renderStatus());
    }

    public function test_a_job_still_waiting_in_the_queue_is_reaped_too(): void
    {
        $file = $this->deliverable('queued', stalledMinutes: 90);

        $this->artisan('renders:reap')->assertSuccessful();

        $this->assertSame('failed', $file->refresh()->renderStatus());
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $file = $this->deliverable('rendering', stalledMinutes: 90);

        $this->artisan('renders:reap', ['--dry-run' => true])
            ->expectsOutputToContain('would fail')
            ->assertSuccessful();

        $this->assertSame('rendering', $file->refresh()->renderStatus());
    }

    public function test_the_window_can_be_tightened(): void
    {
        $file = $this->deliverable('rendering', stalledMinutes: 10);

        $this->artisan('renders:reap')->assertSuccessful();
        $this->assertSame('rendering', $file->refresh()->renderStatus());

        $this->artisan('renders:reap', ['--minutes' => 5])->assertSuccessful();
        $this->assertSame('failed', $file->refresh()->renderStatus());
    }

    private function deliverable(string $status, int $stalledMinutes): ProductFile
    {
        $user = User::create([
            'name' => 'seller',
            'email' => 'seller@example.com',
            'password' => 'password123',
        ]);

        $product = Product::create([
            'name' => 'Half-track',
            'price_cents' => 2450,
            'preview_mode' => Product::PREVIEW_ATTESTED_STILLS,
            'user_id' => $user->id,
        ]);

        $file = $product->files()->create([
            'kind' => ProductFile::KIND_DELIVERABLE,
            'format' => 'obj',
            'disk' => 'models',
            'path' => 'obj_files/model.obj',
            'bytes' => 10,
            'checksum' => str_repeat('a', 64),
            'meta' => [
                'angles' => [['position' => [3, 2, 4], 'target' => [0, 0, 0], 'fov' => 75]],
                'render' => ['status' => $status, 'error' => null],
            ],
        ]);

        // The row's updated_at is when it entered that state.
        ProductFile::whereKey($file->id)->update(['updated_at' => now()->subMinutes($stalledMinutes)]);

        return $file->fresh();
    }
}
