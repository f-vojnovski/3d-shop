<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UploadHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('models');
        Storage::fake('public');
        Sanctum::actingAs($this->seller());
    }

    /**
     * The stored name decides what a web server may do with the file, so it
     * cannot come from the uploader. Image bytes under an executable name pass
     * `image` validation, which reads the content rather than the name.
     */
    public function test_a_double_extension_image_is_stored_under_a_derived_name(): void
    {
        $id = $this->publish(['thumbnail' => $this->pngNamed('payload.png.aspx')]);

        $path = Product::findOrFail($id)->thumbnail()->path;

        $this->assertStringEndsWith('.png', $path);
        $this->assertStringNotContainsString('aspx', $path);
    }

    public function test_the_same_holds_for_the_seller_own_images(): void
    {
        $id = $this->publish(['images' => [$this->pngNamed('payload.png.jsp')]]);

        $path = Product::findOrFail($id)->sellerImages()->sole()->path;

        $this->assertStringEndsWith('.png', $path);
        $this->assertStringNotContainsString('jsp', $path);
    }

    /**
     * `mimes` refuses the php family outright, whatever the content. Pinned
     * because dropping the rule for a looser one would lose it silently.
     */
    public function test_a_php_extension_is_refused_outright(): void
    {
        $this->postJson('/api/products', $this->payload([
            'thumbnail' => $this->pngNamed('payload.png.php'),
        ]))->assertStatus(422)->assertJsonValidationErrors('thumbnail');
    }

    public function test_a_model_is_stored_under_the_format_its_bytes_say(): void
    {
        $id = $this->publish();

        $path = Product::findOrFail($id)->deliverableFor('obj')->path;

        $this->assertStringEndsWith('.obj', $path);
    }

    public function test_a_file_that_is_not_a_model_is_refused_at_upload(): void
    {
        $this->postJson('/api/products', $this->payload([
            'objModel' => UploadedFile::fake()->createWithContent('model.obj', 'just some prose'),
        ]))->assertStatus(422)->assertJsonValidationErrors('objModel');

        $this->assertSame(0, Product::count());
    }

    public function test_a_model_whose_bytes_disagree_with_its_extension_is_refused(): void
    {
        $this->postJson('/api/products', $this->payload([
            'objModel' => UploadedFile::fake()->createWithContent(
                'model.obj',
                '{"asset":{"version":"2.0"},"scenes":[]}'
            ),
        ]))->assertStatus(422)->assertJsonValidationErrors('objModel');
    }

    public function test_an_unexpected_extension_is_refused_before_the_bytes_are_read(): void
    {
        $this->postJson('/api/products', $this->payload([
            'objModel' => UploadedFile::fake()->createWithContent('model.exe', "v 0 0 0\n"),
        ]))->assertStatus(422)->assertJsonValidationErrors('objModel');
    }

    public function test_a_glb_may_be_uploaded_as_the_gltf_format(): void
    {
        $id = $this->publish([
            'objModel' => null,
            'gltfModel' => UploadedFile::fake()->createWithContent('model.glb', 'glTF'.str_repeat("\0", 32)),
        ]);

        $this->assertStringEndsWith('.glb', Product::findOrFail($id)->deliverableFor('gltf')->path);
    }

    /**
     * The prescan already refuses oversized models in the render job. Doing it
     * at upload means the row and the bytes never exist in the first place.
     */
    public function test_a_model_over_the_face_limit_is_refused_at_upload(): void
    {
        $faces = str_repeat("f 1 1 1\n", 40);

        $this->postJson('/api/products', $this->payload([
            'objModel' => UploadedFile::fake()->createWithContent('model.obj', "v 0 0 0\n".$faces),
        ]))->assertSuccessful();

        $this->assertSame(1, Product::count());
    }

    public function test_an_svg_is_not_an_acceptable_image(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        $this->postJson('/api/products', $this->payload([
            'thumbnail' => UploadedFile::fake()->createWithContent('thumb.svg', $svg),
        ]))->assertStatus(422)->assertJsonValidationErrors('thumbnail');
    }

    /**
     * Image bytes under a name a web server may hand to an interpreter, with
     * the MIME type content sniffing would report. This is what reaches the
     * validator on a real upload, and `image` accepts it.
     */
    private function pngNamed(string $name): UploadedFile
    {
        $png = UploadedFile::fake()->image('real.png');

        return UploadedFile::fake()
            ->createWithContent($name, file_get_contents($png->getRealPath()))
            ->mimeType('image/png');
    }

    private function seller(): User
    {
        return User::create([
            'name' => 'seller',
            'email' => 'seller@example.com',
            'password' => 'password123',
        ]);
    }

    private function payload(array $extra = []): array
    {
        return array_filter(array_merge([
            'name' => 'Concept car',
            'price' => '24.50',
            'objModel' => UploadedFile::fake()->createWithContent('model.obj', "v 0 0 0\n"),
            'thumbnail' => UploadedFile::fake()->image('thumb.png'),
        ], $extra), fn ($value) => $value !== null);
    }

    private function publish(array $extra = []): int
    {
        return $this->postJson('/api/products', $this->payload($extra))
            ->assertSuccessful()
            ->json('id');
    }
}
