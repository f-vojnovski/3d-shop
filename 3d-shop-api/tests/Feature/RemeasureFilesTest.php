<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Measuring happens at upload, so a listing keeps whatever the reader could
 * work out on the day. This is how an old record catches up with a reader that
 * has since learned to read more.
 */
class RemeasureFilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('models');
    }

    public function test_it_replaces_facts_measured_by_an_older_reader(): void
    {
        $file = $this->deliverable(['faces' => 1, 'rigged' => false]);

        $this->artisan('models:remeasure')->assertSuccessful();

        $facts = $file->fresh()->facts();

        $this->assertSame(1, $facts['faces']);
        $this->assertArrayHasKey('rig', $facts);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $file = $this->deliverable(['faces' => 999]);

        $this->artisan('models:remeasure --dry-run')->assertSuccessful();

        $this->assertSame(999, $file->fresh()->facts()['faces']);
    }

    public function test_it_can_be_pointed_at_one_product(): void
    {
        $mine = $this->deliverable(['faces' => 111]);
        $other = $this->deliverable(['faces' => 222]);

        $this->artisan('models:remeasure --product='.$mine->product_id)->assertSuccessful();

        $this->assertNotSame(111, $mine->fresh()->facts()['faces']);
        $this->assertSame(222, $other->fresh()->facts()['faces']);
    }

    /** Re-reading every model on a site is expensive; most of them are current. */
    public function test_it_can_skip_records_that_are_already_current(): void
    {
        $done = $this->deliverable(['faces' => 333, 'rig' => ['bones' => 4]]);

        $this->artisan('models:remeasure --missing-only')->assertSuccessful();

        $this->assertSame(333, $done->fresh()->facts()['faces']);
    }

    public function test_a_superseded_file_is_left_alone(): void
    {
        $file = $this->deliverable(['faces' => 444]);
        $file->update(['superseded_at' => now()]);

        $this->artisan('models:remeasure')->assertSuccessful();

        $this->assertSame(444, $file->fresh()->facts()['faces']);
    }

    /** @param  array<string, mixed>  $facts */
    private function deliverable(array $facts): ProductFile
    {
        $seller = User::create([
            'name' => 'seller'.uniqid(),
            'email' => uniqid().'@example.com',
            'password' => 'password123',
        ]);

        $product = Product::create([
            'name' => 'Crate',
            'price_cents' => 500,
            'user_id' => $seller->id,
        ]);

        $body = "v 0 0 0\nv 1 0 0\nv 0 1 0\nf 1 2 3\n";
        $path = 'models/'.uniqid().'.obj';
        Storage::disk('models')->put($path, $body);

        return $product->files()->create([
            'kind' => ProductFile::KIND_DELIVERABLE,
            'format' => 'obj',
            'disk' => 'models',
            'path' => $path,
            'bytes' => strlen($body),
            'checksum' => hash('sha256', $body),
            'meta' => ['facts' => $facts, 'sniffed_format' => 'obj'],
        ]);
    }
}
