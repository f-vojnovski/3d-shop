<?php

namespace Tests\Feature;

use App\Support\MeshPrescan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Admission runs in the web request, so a file too large to decode is refused rather than attempted. */
class PrescanMemoryTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = storage_path('app/private/remeasure/prescan-memory-test');
        File::deleteDirectory($this->scratch);
        File::ensureDirectoryExists($this->scratch, 0775, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    public function test_the_text_limit_is_far_below_the_container_limit(): void
    {
        $this->assertLessThan(
            MeshPrescan::MAX_BYTES,
            MeshPrescan::MAX_JSON_BYTES,
            'The limit protecting a web worker cannot be the one sized for a 2 GB container.'
        );
    }

    /** Refused without being decoded on the way to finding out it is too large. */
    public function test_a_gltf_too_large_to_decode_is_refused_not_attempted(): void
    {
        $path = $this->scratch.DIRECTORY_SEPARATOR.'huge.gltf';
        $this->writeGltfOfAtLeast($path, MeshPrescan::MAX_JSON_BYTES + 1024);

        $before = memory_get_peak_usage(true);
        $scan = MeshPrescan::of($path);
        $spent = memory_get_peak_usage(true) - $before;

        $this->assertFalse($scan->withinLimits());
        $this->assertStringContainsString('describes itself in', (string) $scan->rejection());

        $this->assertLessThan(
            filesize($path),
            $spent,
            'Deciding to refuse the file cost more memory than the file itself.'
        );
    }

    public function test_an_ordinary_gltf_is_still_accepted(): void
    {
        $path = $this->scratch.DIRECTORY_SEPARATOR.'small.gltf';
        File::put($path, json_encode([
            'asset' => ['version' => '2.0'],
            'scenes' => [['nodes' => []]],
            'nodes' => [],
            'meshes' => [],
        ]));

        $scan = MeshPrescan::of($path);

        $this->assertSame('gltf', $scan->format);
        $this->assertTrue($scan->withinLimits());
        $this->assertNull($scan->rejection());
    }

    /** A glb carries its geometry as binary, so its text stays small. */
    public function test_a_glb_is_measured_by_its_json_chunk_not_its_size(): void
    {
        $json = json_encode(['asset' => ['version' => '2.0'], 'meshes' => []]);
        $json = str_pad((string) $json, 4 * (int) ceil(strlen((string) $json) / 4), ' ');
        $binary = str_repeat("\0", 1024);

        $glb = 'glTF'
            .pack('V', 2)
            .pack('V', 12 + 8 + strlen($json) + 8 + strlen($binary))
            .pack('V', strlen($json)).'JSON'.$json
            .pack('V', strlen($binary)).'BIN'."\0".$binary;

        $path = $this->scratch.DIRECTORY_SEPARATOR.'model.glb';
        File::put($path, $glb);

        $scan = MeshPrescan::of($path);

        $this->assertSame('glb', $scan->format);
        $this->assertSame(strlen($json), $scan->jsonBytes, 'Only the JSON chunk has to be held in memory.');
        $this->assertLessThan($scan->bytes, $scan->jsonBytes);
    }

    /** Many small objects, which is what expands. One long string would prove nothing. */
    private function writeGltfOfAtLeast(string $path, int $bytes): void
    {
        $handle = fopen($path, 'wb');
        fwrite($handle, '{"asset":{"version":"2.0"},"accessors":[');

        $one = '{"bufferView":0,"componentType":5126,"count":24,"type":"VEC3","min":[-1,-1,-1],"max":[1,1,1]}';
        $first = true;

        while (ftell($handle) < $bytes) {
            fwrite($handle, ($first ? '' : ',').$one);
            $first = false;
        }

        fwrite($handle, ']}');
        fclose($handle);
    }
}
