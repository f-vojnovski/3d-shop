<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneOrphanedFilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('models');
        Storage::fake('public');
    }

    public function test_a_file_a_product_still_points_at_is_kept(): void
    {
        $this->deliverable('obj_files/live.obj');
        $this->age('models', 'obj_files/live.obj', days: 30);

        $this->artisan('files:prune')->assertSuccessful();

        Storage::disk('models')->assertExists('obj_files/live.obj');
    }

    public function test_an_orphan_older_than_the_window_goes(): void
    {
        Storage::disk('models')->put('obj_files/left-behind.obj', 'bytes');
        $this->age('models', 'obj_files/left-behind.obj', days: 30);

        $this->artisan('files:prune')->assertSuccessful();

        Storage::disk('models')->assertMissing('obj_files/left-behind.obj');
    }

    // An upload in flight has bytes on disk before its row exists.
    public function test_a_recent_orphan_is_left_alone(): void
    {
        Storage::disk('models')->put('obj_files/just-uploaded.obj', 'bytes');

        $this->artisan('files:prune')->assertSuccessful();

        Storage::disk('models')->assertExists('obj_files/just-uploaded.obj');
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        Storage::disk('public')->put('preview_images/old.png', 'bytes');
        $this->age('public', 'preview_images/old.png', days: 30);

        $this->artisan('files:prune', ['--dry-run' => true])
            ->expectsOutputToContain('would delete public:preview_images/old.png')
            ->assertSuccessful();

        Storage::disk('public')->assertExists('preview_images/old.png');
    }

    public function test_the_window_can_be_widened(): void
    {
        Storage::disk('public')->put('preview_images/yesterday.png', 'bytes');
        $this->age('public', 'preview_images/yesterday.png', days: 1);

        $this->artisan('files:prune')->assertSuccessful();
        Storage::disk('public')->assertExists('preview_images/yesterday.png');

        $this->artisan('files:prune', ['--days' => 0])->assertSuccessful();
        Storage::disk('public')->assertMissing('preview_images/yesterday.png');
    }

    public function test_deleting_a_product_leaves_files_the_prune_then_collects(): void
    {
        $file = $this->deliverable('obj_files/doomed.obj');
        $product = $file->product;

        $product->files()->delete();
        $product->delete();

        Storage::disk('models')->assertExists('obj_files/doomed.obj');
        $this->age('models', 'obj_files/doomed.obj', days: 30);

        $this->artisan('files:prune')->assertSuccessful();

        Storage::disk('models')->assertMissing('obj_files/doomed.obj');
    }

    private function deliverable(string $path): ProductFile
    {
        Storage::disk('models')->put($path, 'bytes');

        $user = User::create([
            'name' => 'seller',
            'email' => 'seller@example.com',
            'password' => 'password123',
        ]);

        $product = Product::create([
            'name' => 'Half-track',
            'price_cents' => 2450,
            'user_id' => $user->id,
        ]);

        return $product->files()->create([
            'kind' => ProductFile::KIND_DELIVERABLE,
            'format' => 'obj',
            'disk' => 'models',
            'path' => $path,
            'bytes' => 5,
            'checksum' => str_repeat('a', 64),
        ]);
    }

    private function age(string $disk, string $path, int $days): void
    {
        touch(
            Storage::disk($disk)->path($path),
            now()->subDays($days)->getTimestamp()
        );
    }
}
