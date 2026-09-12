<?php

namespace Tests\Feature;

use App\Support\RigFacts;
use Tests\TestCase;

/**
 * The rig numbers a listing shows are read straight out of the file, so these
 * build files byte by byte rather than leaning on a fixture: the point is that
 * a given arrangement of bytes produces a given claim about someone's model.
 */
class RigFactsTest extends TestCase
{
    /** @var list<string> */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_it_counts_the_bones_a_skin_declares(): void
    {
        $facts = $this->read($this->rig(bones: 6));

        $this->assertSame(6, $facts['bones']);
        $this->assertSame(1, $facts['skins']);
    }

    /**
     * The most useful line in the whole report: an export often carries a
     * control rig whose bones never touch the mesh.
     */
    public function test_it_separates_bones_that_do_work_from_bones_that_are_only_there(): void
    {
        // Six bones declared, but every vertex is pulled by bones 0 and 1.
        $facts = $this->read($this->rig(
            bones: 6,
            joints: [[0, 1, 0, 0], [0, 1, 0, 0], [1, 0, 0, 0]],
            weights: [[0.5, 0.5, 0, 0], [0.2, 0.8, 0, 0], [1, 0, 0, 0]],
        ));

        $this->assertSame(6, $facts['bones']);
        $this->assertSame(2, $facts['bones_used']);
    }

    public function test_it_finds_points_no_bone_pulls_on(): void
    {
        $facts = $this->read($this->rig(
            joints: [[0, 0, 0, 0], [0, 0, 0, 0], [1, 0, 0, 0]],
            weights: [[1, 0, 0, 0], [0, 0, 0, 0], [1, 0, 0, 0]],
        ));

        $this->assertSame(3, $facts['skinned_vertices']);
        $this->assertSame(1, $facts['unweighted_vertices']);
    }

    public function test_it_finds_weights_that_do_not_add_up(): void
    {
        $facts = $this->read($this->rig(
            joints: [[0, 1, 0, 0], [0, 1, 0, 0]],
            weights: [[0.5, 0.5, 0, 0], [0.9, 0.9, 0, 0]],
        ));

        $this->assertSame(1, $facts['unnormalised_vertices']);
    }

    public function test_it_reports_how_many_bones_pull_hardest_on_one_point(): void
    {
        $rigid = $this->read($this->rig(
            joints: [[0, 0, 0, 0]],
            weights: [[1, 0, 0, 0]],
        ));

        $blended = $this->read($this->rig(
            bones: 4,
            joints: [[0, 1, 2, 3]],
            weights: [[0.25, 0.25, 0.25, 0.25]],
        ));

        $this->assertSame(1, $rigid['max_influences']);
        $this->assertSame(4, $blended['max_influences']);
        $this->assertSame(1, $blended['vertices_on_four_bones']);
    }

    /** Four words that tell a game developer whether their library will fit. */
    public function test_it_names_the_family_a_skeleton_came_from(): void
    {
        $this->assertSame('Mixamo', $this->read($this->rig(names: ['mixamorig:Hips', 'mixamorig:Spine']))['naming']);
        $this->assertSame('Unreal Engine', $this->read($this->rig(names: ['pelvis', 'spine_01', 'clavicle_l']))['naming']);
        $this->assertSame('Blender Rigify', $this->read($this->rig(names: ['spine', 'spine.001', 'upper_arm.L']))['naming']);
    }

    public function test_it_says_nothing_rather_than_guess_at_an_unfamiliar_skeleton(): void
    {
        $facts = $this->read($this->rig(names: ['Bone', 'Bone_001', '__0']));

        $this->assertArrayNotHasKey('naming', $facts);
    }

    public function test_it_lists_each_clip_with_its_name_and_length(): void
    {
        $facts = $this->read($this->rig(clips: [['Walk', 1.5], ['Run', 0.75]]));

        $this->assertSame(['Walk', 'Run'], array_column($facts['clips'], 'name'));
        $this->assertSame([1.5, 0.75], array_column($facts['clips'], 'seconds'));
    }

    /**
     * A walk that drives a character across the floor moves its root by most of
     * a stride. A walk on the spot moves it by the sway of a torso, and the two
     * are only told apart by the size of the thing walking.
     */
    public function test_a_root_that_crosses_the_floor_is_travelling(): void
    {
        $facts = $this->read(
            $this->rig(clips: [['Walk', 1.0]], travel: [[0, 0, 0], [0, 0, 4.0]]),
            size: 2.0
        );

        $this->assertTrue($facts['clips'][0]['travels']);
        $this->assertSame(4.0, $facts['clips'][0]['root_travel']);
    }

    public function test_a_root_that_only_sways_is_not_travelling(): void
    {
        $facts = $this->read(
            $this->rig(clips: [['Walk', 1.0]], travel: [[0, 0, 0], [0, 0.05, 0.05]]),
            size: 2.0
        );

        $this->assertFalse($facts['clips'][0]['travels']);
    }

    /** Same movement, smaller creature: the number alone decides nothing. */
    public function test_the_same_distance_reads_differently_on_a_smaller_model(): void
    {
        $rig = $this->rig(clips: [['Walk', 1.0]], travel: [[0, 0, 0], [0, 0, 0.5]]);

        $this->assertTrue($this->read($rig, size: 1.0)['clips'][0]['travels']);
        $this->assertFalse($this->read($rig, size: 20.0)['clips'][0]['travels']);
    }

    /** Without a measured size there is no honest answer, so none is given. */
    public function test_it_withholds_the_verdict_when_it_does_not_know_the_size(): void
    {
        $facts = $this->read($this->rig(clips: [['Walk', 1.0]], travel: [[0, 0, 0], [0, 0, 4.0]]));

        $this->assertArrayNotHasKey('travels', $facts['clips'][0]);
        $this->assertSame(4.0, $facts['clips'][0]['root_travel']);
    }

    public function test_a_file_with_no_rig_and_no_animation_reports_nothing(): void
    {
        $handle = $this->bytes('');

        $this->assertNull(RigFacts::of(['nodes' => [], 'meshes' => []], $handle, 0));
    }

    // ------------------------------------------------------------- fixtures

    /**
     * @param  array<string, mixed>  $rig
     * @return array<string, mixed>
     */
    private function read(array $rig, ?float $size = null): array
    {
        $facts = RigFacts::of($rig['gltf'], $this->bytes($rig['bin']), 0, $size);

        $this->assertNotNull($facts, 'the file was read as having no rig at all');

        return $facts;
    }

    /**
     * Builds the glTF header and binary block for a skinned mesh.
     *
     * @param  list<list<int>>|null  $joints    four bone numbers per vertex
     * @param  list<list<float>>|null  $weights four pulls per vertex
     * @param  list<string>|null  $names       what the bones are called
     * @param  list<array{0: string, 1: float}>  $clips  name and length
     * @param  list<list<float>>|null  $travel  where the root sits, key by key
     * @return array{gltf: array<string, mixed>, bin: string}
     */
    private function rig(
        int $bones = 2,
        ?array $joints = null,
        ?array $weights = null,
        ?array $names = null,
        array $clips = [],
        ?array $travel = null,
    ): array {
        $joints ??= [[0, 0, 0, 0]];
        $weights ??= [[1, 0, 0, 0]];
        $names ??= array_map(fn ($at) => 'Bone_'.$at, range(0, $bones - 1));
        $bones = max($bones, count($names));

        $bin = '';
        $accessors = [];
        $views = [];

        $add = function (string $packed, int $count, int $componentType, string $type) use (&$bin, &$accessors, &$views): int {
            $views[] = ['buffer' => 0, 'byteOffset' => strlen($bin), 'byteLength' => strlen($packed)];
            $accessors[] = [
                'bufferView' => count($views) - 1,
                'componentType' => $componentType,
                'count' => $count,
                'type' => $type,
            ];
            $bin .= $packed;

            return count($accessors) - 1;
        };

        $jointsAt = $add(
            pack('C*', ...array_merge(...$joints)),
            count($joints),
            5121,
            'VEC4'
        );
        $weightsAt = $add(
            pack('g*', ...array_merge(...$weights)),
            count($weights),
            5126,
            'VEC4'
        );

        // Node 0 is the mesh; the bones follow it, the first hung under node 0
        // so it is a root bone without being a root node.
        $nodes = [['mesh' => 0, 'children' => [1]]];

        foreach ($names as $at => $name) {
            $nodes[] = array_filter([
                'name' => $name,
                'children' => $at + 2 <= $bones ? [$at + 2] : null,
            ], fn ($value) => $value !== null);
        }

        $gltf = [
            'nodes' => $nodes,
            'meshes' => [['primitives' => [[
                'attributes' => ['JOINTS_0' => $jointsAt, 'WEIGHTS_0' => $weightsAt],
            ]]]],
            'skins' => [['joints' => range(1, $bones), 'skeleton' => 1]],
            'bufferViews' => &$views,
            'accessors' => &$accessors,
        ];

        foreach ($clips as $index => [$name, $seconds]) {
            $times = $travel === null ? [0.0, $seconds] : array_map(
                fn ($at) => $seconds * $at / max(count($travel) - 1, 1),
                range(0, count($travel) - 1)
            );

            $inputAt = $add(pack('g*', ...$times), count($times), 5126, 'SCALAR');
            $accessors[$inputAt]['max'] = [$seconds];

            $values = $travel ?? array_fill(0, count($times), [0.0, 0.0, 0.0]);
            $outputAt = $add(pack('g*', ...array_merge(...$values)), count($values), 5126, 'VEC3');

            $gltf['animations'][] = [
                'name' => $name,
                'samplers' => [['input' => $inputAt, 'output' => $outputAt]],
                'channels' => [['sampler' => 0, 'target' => ['node' => 1, 'path' => 'translation']]],
            ];
        }

        $gltf['bufferViews'] = $views;
        $gltf['accessors'] = $accessors;

        return ['gltf' => $gltf, 'bin' => $bin];
    }

    /** @return resource */
    private function bytes(string $bin)
    {
        $path = tempnam(sys_get_temp_dir(), 'rig');
        file_put_contents($path, $bin);
        $this->scratch[] = $path;

        return fopen($path, 'rb');
    }
}
