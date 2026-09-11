<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ModelConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Without this an `.fbx` seller has nothing to aim a camera at. */
class ConvertForFramingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::create([
            'name' => 'seller',
            'email' => 'seller@example.com',
            'password' => 'password123',
        ]));
    }

    public function test_it_returns_a_browser_readable_copy(): void
    {
        $this->app->instance(ModelConverter::class, new class extends ModelConverter
        {
            public function toGlb(string $sourcePath, string $scratchDir): array
            {
                $path = $scratchDir.DIRECTORY_SEPARATOR.'converted.glb';
                file_put_contents($path, 'glTF-BINARY-BYTES');

                return ['status' => 'ok', 'path' => $path, 'tool' => 'assimp test'];
            }
        });

        $response = $this->post('/api/uploads/convert', ['model' => $this->fbx()]);

        $response->assertSuccessful();
        $response->assertHeader('Content-Type', 'model/gltf-binary');
        $this->assertSame('glTF-BINARY-BYTES', $response->getContent());
    }

    /** Nothing is kept: the copy exists for one screen in one browser. */
    public function test_it_leaves_nothing_on_disk(): void
    {
        $this->app->instance(ModelConverter::class, new class extends ModelConverter
        {
            public function toGlb(string $sourcePath, string $scratchDir): array
            {
                $path = $scratchDir.DIRECTORY_SEPARATOR.'converted.glb';
                file_put_contents($path, 'glTF');

                return ['status' => 'ok', 'path' => $path, 'tool' => 'assimp test'];
            }
        });

        $this->post('/api/uploads/convert', ['model' => $this->fbx()])->assertSuccessful();

        $this->assertSame([], glob(storage_path('app/private/convert/*')) ?: []);
    }

    public function test_a_format_the_browser_already_reads_is_refused(): void
    {
        $this->post('/api/uploads/convert', [
            'model' => UploadedFile::fake()->createWithContent('car.obj', "v 0 0 0\nf 1 1 1\n"),
        ])->assertStatus(422)->assertJsonValidationErrors('model');
    }

    public function test_a_refused_conversion_says_why(): void
    {
        $this->app->instance(ModelConverter::class, new class extends ModelConverter
        {
            public function toGlb(string $sourcePath, string $scratchDir): array
            {
                return ['status' => 'failed', 'reason' => 'That file could not be read for rendering.'];
            }
        });

        $this->post('/api/uploads/convert', ['model' => $this->fbx()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('model');
    }

    public function test_a_signed_out_visitor_cannot_start_a_container(): void
    {
        app('auth')->forgetGuards();

        $this->postJson('/api/uploads/convert', [])->assertUnauthorized();
    }

    /** Binary `.fbx` starts with this; the prescan sniffs it rather than the name. */
    private function fbx(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'tower.fbx',
            "Kaydara FBX Binary  \x00".str_repeat("\x00", 64)
        );
    }
}
