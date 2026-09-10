<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaMergeTest extends TestCase
{
    use RefreshDatabase;

    private const ANGLE = ['position' => [3, 2, 4], 'target' => [0, 0, 0], 'fov' => 75];

    /**
     * A job settles from an instance it loaded minutes earlier, so the merge
     * has to read the row as it is now rather than as the job remembers it.
     */
    public function test_settling_merges_against_the_stored_row_not_a_stale_copy(): void
    {
        $file = $this->deliverable();
        $asTheJobSeesIt = ProductFile::findOrFail($file->id);

        ProductFile::findOrFail($file->id)->withMeta([
            'angles' => [self::ANGLE, self::ANGLE, self::ANGLE],
        ]);

        $asTheJobSeesIt->withMeta(['render' => ['status' => 'ready', 'error' => null]]);

        $after = ProductFile::findOrFail($file->id);
        $this->assertCount(3, $after->angles());
        $this->assertSame('ready', $after->renderStatus());
    }

    public function test_merging_keeps_the_keys_it_was_not_given(): void
    {
        $file = $this->deliverable();

        $file->withMeta(['render' => ['status' => 'rendering', 'error' => null]]);

        $after = ProductFile::findOrFail($file->id);
        $this->assertSame('obj', $after->meta['sniffed_format']);
        $this->assertCount(1, $after->angles());
    }

    public function test_the_instance_reflects_what_was_written(): void
    {
        $file = $this->deliverable();

        $file->withMeta(['render' => ['status' => 'failed', 'error' => 'Nothing rendered.']]);

        $this->assertSame('failed', $file->renderStatus());
        $this->assertSame('Nothing rendered.', $file->renderError());
        $this->assertFalse($file->isDirty());
    }

    private function deliverable(): ProductFile
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

        return $product->files()->create([
            'kind' => ProductFile::KIND_DELIVERABLE,
            'format' => 'obj',
            'disk' => 'models',
            'path' => 'obj_files/model.obj',
            'bytes' => 100,
            'checksum' => str_repeat('a', 64),
            'meta' => [
                'sniffed_format' => 'obj',
                'triangles' => 10,
                'angles' => [self::ANGLE],
                'render' => ['status' => 'queued', 'error' => null],
            ],
        ]);
    }
}
