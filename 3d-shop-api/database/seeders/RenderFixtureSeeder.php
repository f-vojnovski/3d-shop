<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** A committed model and the hashes it must still produce, recorded by hand so drift fails the job. */
class RenderFixtureSeeder extends Seeder
{
    public const PRODUCT = 'CI render fixture';

    public const FIXTURE = 'tests/fixtures/cube.gltf';

    /** The host that produced the hashes below, since SwiftShader builds for its host. */
    public const RECORDED_ON = 'Intel(R) Core(TM) i7-10700 CPU @ 2.90GHz';

    public const ANGLES = [
        ['position' => [3.5, 2.5, 3.5], 'target' => [0, 0, 0], 'up' => [0, 1, 0], 'fov' => 45],
        ['position' => [-3.5, 1.5, 3.5], 'target' => [0, 0, 0], 'up' => [0, 1, 0], 'fov' => 45],
    ];

    /** @var list<array{shaded: string, wireframe: string}> */
    public const EXPECTED = [
        [
            'shaded' => '4cd09433c87ca6c21bdfebda16c730deb6d761ecc7a2e15d0c8eed9fb76b3903',
            'wireframe' => '60ea0113038fb14a1083f23f7ce510357e091193b104fb744eba9074e2951dd0',
        ],
        [
            'shaded' => '8a7d4ab46f5d31c60131140551ac5c81641328b962e951d0172d8e184a2c0761',
            'wireframe' => 'f72ac9efd1aa64221fcdb5abe7563b4c5423725ce614253fb2261dc8c00e41ee',
        ],
    ];

    public function run(): void
    {
        $fixture = base_path(self::FIXTURE);
        $bytes = (string) file_get_contents($fixture);
        $checksum = hash('sha256', $bytes);
        $path = 'ci/cube.gltf';

        // The models disk is configured not to throw, so an unchecked write fails silently.
        if (! Storage::disk('models')->put($path, $bytes)) {
            throw new RuntimeException('Could not write the fixture to the models disk.');
        }

        $user = User::firstOrCreate(
            ['name' => 'ci-fixture'],
            ['email' => 'ci-fixture@example.test', 'password' => 'not-a-login-account'],
        );

        Product::where('name', self::PRODUCT)->delete();

        $product = Product::create([
            'name' => self::PRODUCT,
            'description' => 'A cube, so a renderer change shows up as changed pixels.',
            'price_cents' => 0,
            'currency' => 'USD',
            'user_id' => $user->id,
            'unlisted' => true,
            'published_at' => now(),
        ]);

        $source = ProductFile::create([
            'product_id' => $product->id,
            'kind' => ProductFile::KIND_DELIVERABLE,
            'format' => 'gltf',
            'disk' => 'models',
            'path' => $path,
            'sort' => 0,
            'bytes' => strlen($bytes),
            'checksum' => $checksum,
            'meta' => [
                'angles' => self::ANGLES,
                'faces' => 12,
                'sniffed_format' => 'gltf',
                'facts' => ['faces' => 12, 'vertices' => 24, 'topology' => 'triangles'],
            ],
        ]);

        foreach (self::EXPECTED as $index => $hashes) {
            foreach ([ProductFile::KIND_PREVIEW_IMAGE => 'shaded', ProductFile::KIND_WIREFRAME => 'wireframe'] as $kind => $key) {
                ProductFile::create([
                    'product_id' => $product->id,
                    'source_file_id' => $source->id,
                    'kind' => $kind,
                    'format' => 'png',
                    'disk' => 'public',
                    'path' => "ci/cube-{$key}-{$index}.png",
                    'sort' => $index,
                    'bytes' => 0,
                    'checksum' => $hashes[$key],
                    'meta' => [
                        'camera' => self::ANGLES[$index],
                        'source_checksum' => $checksum,
                    ],
                ]);
            }
        }
    }
}
