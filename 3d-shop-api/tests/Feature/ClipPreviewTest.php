<?php

namespace Tests\Feature;

use App\Jobs\RenderClipPreview;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use App\Support\AnimateRunner;
use App\Support\ModelConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClipPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('models');
        Storage::fake('public');
    }

    public function test_a_clip_is_stored_once_per_pass(): void
    {
        $source = $this->deliverable();

        $this->draw($source, ['shaded', 'influence', 'bones']);

        $clips = $source->fresh()->clips()->get();

        $this->assertCount(3, $clips);
        $this->assertEqualsCanonicalizing(
            ['shaded', 'influence', 'bones'],
            $clips->pluck('meta.pass')->all()
        );
    }

    /** Pixels, so they are shown like any other picture. */
    public function test_a_clip_is_a_picture_on_the_public_disk(): void
    {
        $source = $this->deliverable();

        $this->draw($source, ['shaded']);

        $clip = $source->fresh()->clips()->firstOrFail();

        $this->assertSame('public', $clip->disk);
        $this->assertSame('webp', $clip->format);
        Storage::disk('public')->assertExists($clip->path);
    }

    public function test_a_clip_carries_what_it_is_a_clip_of(): void
    {
        $source = $this->deliverable();

        $this->draw($source, ['shaded'], clip: 1);

        $meta = $source->fresh()->clips()->firstOrFail()->meta;

        $this->assertSame(1, $meta['clip']['index']);
        $this->assertSame('Run', $meta['clip']['name']);
        $this->assertSame(24, $meta['frames']);
        $this->assertNotEmpty($meta['camera']);
    }

    /** Redrawing the walk has not withdrawn the run. */
    public function test_redrawing_one_clip_leaves_the_others_alone(): void
    {
        $source = $this->deliverable();

        $this->draw($source, ['shaded'], clip: 0);
        $this->draw($source, ['shaded'], clip: 1);
        $this->draw($source, ['shaded'], clip: 0);

        $clips = $source->fresh()->clips()->get();

        $this->assertCount(2, $clips);
        $this->assertEqualsCanonicalizing([0, 1], $clips->pluck('meta.clip.index')->all());
    }

    public function test_a_pass_the_page_cannot_paint_never_reaches_the_container(): void
    {
        $runner = new AnimateRunner();
        $scratch = $this->scratch();

        $runner->run(0, 24, 512, ['position' => [0, 1, 6]], ['shaded', 'x-ray'], 'glb', $scratch.'/model', $scratch);

        $job = json_decode((string) file_get_contents($scratch.'/job.json'), true);

        $this->assertSame(['shaded'], $job['passes']);
    }

    public function test_the_payload_offers_a_clip_first_and_its_paints_second(): void
    {
        $source = $this->deliverable();

        $this->draw($source, ['shaded', 'influence', 'bones'], clip: 0);
        $this->draw($source, ['shaded'], clip: 1);

        $clips = $this->getJson('/api/products/'.$source->product_id)->json('clips');

        $this->assertCount(2, $clips);
        $this->assertSame(['Walk', 'Run'], array_column($clips, 'name'));
        $this->assertCount(3, $clips[0]['passes']);
        $this->assertCount(1, $clips[1]['passes']);
        $this->assertStringEndsWith('.webp', $clips[0]['passes'][0]['url']);
    }

    /** The sandbox is argv, so nothing else in the suite would notice it going. */
    public function test_a_clip_is_drawn_confined(): void
    {
        $argv = (new AnimateRunner())->commandFor('/s/model', '/s/job.json', '/s/out');

        $this->assertContains('--network=none', $argv);
        $this->assertContains('--cap-drop=ALL', $argv);
        $this->assertContains('--pids-limit=256', $argv);
        $this->assertNotContains('--privileged', $argv);

        // Its own entrypoint: an attested still is reproduced from the render
        // path, which must not be reachable from here.
        $this->assertContains('/app/animate.mjs', $argv);
        $this->assertNotContains('/app/render.mjs', $argv);

        $this->assertTrue(
            collect($argv)->contains(fn ($one) => str_ends_with((string) $one, ':/in/model:ro')),
            'the model is not mounted read-only'
        );
    }

    /**
     * Stands in for the container: writes the files the real harness would, so
     * the job's own half is what is under test.
     *
     * @param  list<string>  $passes
     */
    private function draw(ProductFile $source, array $passes, int $clip = 0): void
    {
        $runner = $this->createMock(AnimateRunner::class);

        $runner->method('run')->willReturnCallback(
            function (int $at, int $frames, int $size, array $camera, array $wanted, string $format, string $model, string $scratch) use ($passes) {
                $out = $scratch.DIRECTORY_SEPARATOR.'out';
                @mkdir($out, 0775, true);

                $files = [];

                foreach ($passes as $pass) {
                    file_put_contents($out.DIRECTORY_SEPARATOR."clip-{$pass}.webp", 'RIFF....WEBP'.$pass);
                    $files[] = ['pass' => $pass, 'file' => "clip-{$pass}.webp", 'bytes' => 12 + strlen($pass)];
                }

                return [
                    'status' => 'ok',
                    'files' => $files,
                    'clip' => ['index' => $at, 'name' => $at === 1 ? 'Run' : 'Walk', 'seconds' => 1.0],
                    'clips' => [
                        ['index' => 0, 'name' => 'Walk', 'seconds' => 1.0],
                        ['index' => 1, 'name' => 'Run', 'seconds' => 0.5],
                    ],
                    'frames' => $frames,
                    'frameMs' => 42,
                    'width' => $size,
                    'height' => $size,
                    'seconds' => 9.0,
                ];
            }
        );

        (new RenderClipPreview($source->id, clip: $clip, passes: $passes))
            ->handle($runner, app(ModelConverter::class));
    }

    private function scratch(): string
    {
        $path = storage_path('app/private/clip-test-'.uniqid());
        @mkdir($path, 0775, true);
        file_put_contents($path.'/model', 'x');

        return $path;
    }

    private function deliverable(): ProductFile
    {
        Sanctum::actingAs(User::create([
            'name' => 'seller'.uniqid(),
            'email' => uniqid().'@example.com',
            'password' => 'password123',
        ]));

        $id = $this->postJson('/api/products', [
            'name' => 'Gun bot',
            'price' => '30.00',
            'objModel' => UploadedFile::fake()->createWithContent('model.obj', "v 0 0 0\n"),
            'thumbnails' => [UploadedFile::fake()->image('thumb.png')],
        ])->assertSuccessful()->json('id');

        return Product::findOrFail($id)->deliverableFor('obj');
    }
}
