<?php

namespace Tests\Feature;

use App\Support\MeshPrescan;
use Tests\TestCase;

class MeshPrescanTest extends TestCase
{
    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mesh');
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_it_counts_obj_faces_without_parsing(): void
    {
        $obj = "# a cube-ish thing\n"
            ."v 0 0 0\nv 1 0 0\nv 0 1 0\nv 1 1 0\n"
            ."f 1 2 3\nf 2 4 3\nf 1 3 4\n";

        $scan = MeshPrescan::of($this->tempFile($obj));

        $this->assertSame('obj', $scan->format);
        $this->assertSame(3, $scan->faces);
        $this->assertNull($scan->rejection());
    }

    public function test_it_counts_faces_across_a_chunk_boundary(): void
    {
        // Larger than the 4 MB read chunk, so face lines straddle reads.
        $body = str_repeat("v 0 0 0\n", 200_000).str_repeat("f 1 2 3\n", 200_000);

        $scan = MeshPrescan::of($this->tempFile($body));

        $this->assertSame(200_000, $scan->faces);
    }

    public function test_it_identifies_a_glb_by_magic_bytes_not_extension(): void
    {
        $scan = MeshPrescan::of($this->tempFile("glTF\x02\x00\x00\x00rest of the binary"));

        $this->assertSame('glb', $scan->format);
        $this->assertNull($scan->rejection());
    }

    public function test_it_identifies_a_gltf_json_document(): void
    {
        $scan = MeshPrescan::of($this->tempFile('{"asset":{"version":"2.0"},"scenes":[]}'));

        $this->assertSame('gltf', $scan->format);
    }

    public function test_it_rejects_a_file_that_is_not_a_model(): void
    {
        $scan = MeshPrescan::of($this->tempFile("<?php echo 'pwned';"));

        $this->assertSame('unknown', $scan->format);
        $this->assertStringContainsString('not a recognised', $scan->rejection());
    }

    public function test_it_rejects_a_model_over_the_triangle_limit(): void
    {
        $scan = new MeshPrescan(
            bytes: 900_000_000,
            faces: MeshPrescan::MAX_FACES + 1,
            format: 'obj',
        );

        $this->assertFalse($scan->withinLimits());
        $this->assertStringContainsString('over the', $scan->rejection());
    }

    public function test_it_accepts_a_model_exactly_at_the_limit(): void
    {
        $scan = new MeshPrescan(
            bytes: 1,
            faces: MeshPrescan::MAX_FACES,
            format: 'obj',
        );

        $this->assertTrue($scan->withinLimits());
        $this->assertNull($scan->rejection());
    }
}
