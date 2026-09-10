<?php

namespace Tests\Feature;

use App\Support\MeshFacts;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MeshFactsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/facts');
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_it_measures_an_obj(): void
    {
        $facts = MeshFacts::of($this->obj(
            "v 0 0 0\nv 1 0 0\nv 0 2 0\nvn 0 0 1\nvt 0 0\nusemtl body\nf 1/1/1 2/1/1 3/1/1\n"
        ), 'obj');

        $this->assertSame(3, $facts->vertices);
        $this->assertSame(1, $facts->faces);
        $this->assertSame(MeshFacts::TRIANGLES, $facts->topology);
        $this->assertTrue($facts->normals);
        $this->assertTrue($facts->uvs);
        $this->assertSame([1.0, 2.0, 0.0], $facts->bounds['size']);
    }

    public function test_it_tells_quads_from_triangles_and_a_mix(): void
    {
        $vertices = "v 0 0 0\nv 1 0 0\nv 1 1 0\nv 0 1 0\n";

        $this->assertSame(
            MeshFacts::QUADS,
            MeshFacts::of($this->obj($vertices."f 1 2 3 4\n"), 'obj')->topology
        );
        $this->assertSame(
            MeshFacts::MIXED,
            MeshFacts::of($this->obj($vertices."f 1 2 3 4\nf 1 2 3\n"), 'obj')->topology
        );
    }

    public function test_a_bare_obj_claims_nothing(): void
    {
        $facts = MeshFacts::of($this->obj("v 0 0 0\nv 1 0 0\nv 0 1 0\nf 1 2 3\n"), 'obj');

        $this->assertNull($facts->materials);
        $this->assertFalse($facts->uvs);
        $this->assertFalse($facts->normals);
    }

    /**
     * The flag has to mean "this model has usable UVs", not "somebody wrote a
     * vt line". A seller could otherwise advertise a texture-ready mesh by
     * declaring coordinates that no face references.
     */
    public function test_declared_coordinates_no_face_uses_do_not_count(): void
    {
        $facts = MeshFacts::of($this->obj(
            "v 0 0 0\nv 1 0 0\nv 0 2 0\nvt 0 0\nvn 0 0 1\nf 1 2 3\n"
        ), 'obj');

        $this->assertFalse($facts->uvs);
        $this->assertFalse($facts->normals);
    }

    public function test_normals_can_be_referenced_without_uvs(): void
    {
        $facts = MeshFacts::of($this->obj(
            "v 0 0 0\nv 1 0 0\nv 0 2 0\nvt 0 0\nvn 0 0 1\nf 1//1 2//1 3//1\n"
        ), 'obj');

        $this->assertFalse($facts->uvs);
        $this->assertTrue($facts->normals);
    }

    /** The .mtl cannot be uploaded, so no number here would be honest. */
    public function test_an_obj_never_claims_materials(): void
    {
        $facts = MeshFacts::of($this->obj(
            "usemtl paint\nv 0 0 0\nv 1 0 0\nv 0 2 0\nf 1 2 3\n"
        ), 'obj');

        $this->assertNull($facts->materials);
    }

    public function test_it_measures_a_glb(): void
    {
        $facts = MeshFacts::of($this->glb(), 'glb');

        $this->assertSame(3, $facts->vertices);
        $this->assertSame(1, $facts->faces);
        $this->assertSame(MeshFacts::TRIANGLES, $facts->topology);
        $this->assertSame(1, $facts->materials);
        $this->assertSame([1.0, 2.0, 0.0], $facts->bounds['size']);
    }

    public function test_a_node_transform_reaches_the_bounding_box(): void
    {
        $facts = MeshFacts::of($this->glb(['scale' => [2, 2, 2]]), 'glb');

        $this->assertSame([2.0, 4.0, 0.0], $facts->bounds['size']);
    }

    /**
     * Rotating the corners of an axis-aligned box yields a box that contains
     * the rotated geometry instead of hugging it. Here that shortcut would
     * report 2.1213 on the Y axis against a true 1.4142.
     */
    public function test_a_rotated_node_is_measured_from_its_vertices_not_its_box(): void
    {
        $eighth = ['rotation' => [0, 0, sin(M_PI / 8), cos(M_PI / 8)]];

        $size = MeshFacts::of($this->glb($eighth), 'glb')->bounds['size'];

        $this->assertEqualsWithDelta(2.1213, $size[0], 0.001);
        $this->assertEqualsWithDelta(1.4142, $size[1], 0.001);
    }

    public function test_it_reports_rigging_and_animation(): void
    {
        $facts = MeshFacts::of($this->glb([], ['skins' => [[]], 'animations' => [[]]]), 'glb');

        $this->assertTrue($facts->rigged);
        $this->assertTrue($facts->animated);
    }

    public function test_an_unreadable_file_measures_to_nothing_rather_than_throwing(): void
    {
        $facts = MeshFacts::of($this->obj('not a model at all'), 'obj');

        $this->assertSame(0, $facts->vertices);
        $this->assertNull($facts->bounds);
        $this->assertSame(MeshFacts::UNKNOWN, $facts->topology);
    }

    private function obj(string $contents): string
    {
        $path = $this->directory.'/model.obj';
        File::put($path, $contents);

        return $path;
    }

    /** A minimal but valid GLB: one triangle, one material, no textures. */
    private function glb(array $node = [], array $extra = []): string
    {
        $positions = pack('g9', 0, 0, 0, 1, 0, 0, 0, 2, 0);

        $gltf = json_encode(array_merge([
            'asset' => ['version' => '2.0'],
            'scene' => 0,
            'scenes' => [['nodes' => [0]]],
            'nodes' => [array_merge(['mesh' => 0], $node)],
            'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0], 'mode' => 4]]]],
            'accessors' => [[
                'bufferView' => 0,
                'componentType' => 5126,
                'count' => 3,
                'type' => 'VEC3',
                'min' => [0, 0, 0],
                'max' => [1, 2, 0],
            ]],
            'bufferViews' => [['buffer' => 0, 'byteOffset' => 0, 'byteLength' => strlen($positions)]],
            'buffers' => [['byteLength' => strlen($positions)]],
            'materials' => [[]],
        ], $extra));

        $json = $gltf.str_repeat(' ', (4 - (strlen($gltf) % 4)) % 4);
        $bin = $positions.str_repeat("\0", (4 - (strlen($positions) % 4)) % 4);

        $body = pack('VV', strlen($json), 0x4e4f534a).$json
            .pack('VV', strlen($bin), 0x004e4942).$bin;

        $path = $this->directory.'/model.glb';
        File::put($path, 'glTF'.pack('VV', 2, 12 + strlen($body)).$body);

        return $path;
    }
}
