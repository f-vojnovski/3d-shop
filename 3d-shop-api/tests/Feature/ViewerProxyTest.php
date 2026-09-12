<?php

namespace Tests\Feature;

use App\Jobs\BuildViewerProxy;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use App\Support\ProxyRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ViewerProxyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('models');
        Storage::fake('public');
    }

    public function test_a_seller_who_wants_a_cut_down_copy_gets_one_built(): void
    {
        $id = $this->publish(['proxy_mode' => 'model', 'proxy_ratio' => '0.25']);

        $source = Product::findOrFail($id)->deliverableFor('obj');

        $this->assertSame('model', $source->meta['proxy']['mode']);
        $this->assertSame(0.25, $source->meta['proxy']['ratio']);
        Queue::assertPushed(BuildViewerProxy::class);
    }

    /** The box is the absence of a proxy, not a proxy of nothing. */
    public function test_asking_for_the_outline_box_builds_nothing(): void
    {
        $id = $this->publish(['proxy_mode' => 'box']);

        $source = Product::findOrFail($id)->deliverableFor('obj');
        $this->assertSame('box', $source->meta['proxy']['mode']);

        // The job is still queued; it is the job that decides there is nothing
        // to do, so a seller changing their mind later needs no new dispatch.
        (new BuildViewerProxy($source->id))->handle(
            $this->createMock(ProxyRunner::class),
            app(\App\Support\ModelConverter::class)
        );

        $this->assertNull($source->fresh()->proxy());
    }

    public function test_a_ratio_outside_the_slider_is_refused(): void
    {
        Sanctum::actingAs($this->seller());

        $this->postJson('/api/products', $this->payload(['proxy_ratio' => '1.4']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('proxy_ratio');
    }

    public function test_the_route_says_nothing_when_there_is_no_proxy(): void
    {
        $id = $this->publish(['proxy_mode' => 'box']);

        $this->getJson("/api/products/{$id}/proxy/obj")->assertStatus(404);
    }

    public function test_the_route_hands_over_the_proxy_when_there_is_one(): void
    {
        $id = $this->publish(['proxy_mode' => 'model', 'proxy_ratio' => '0.1']);
        $this->giveItAProxy($id);

        $response = $this->get("/api/products/{$id}/proxy/obj");

        $response->assertSuccessful();
        $this->assertSame('model/gltf-binary', $response->headers->get('content-type'));
    }

    public function test_the_payload_points_a_buyer_at_the_proxy(): void
    {
        $id = $this->publish(['proxy_mode' => 'model', 'proxy_ratio' => '0.1']);

        $before = $this->getJson("/api/products/{$id}")->json('previews.0.proxy');
        $this->assertNull($before);

        $this->giveItAProxy($id);

        $after = $this->getJson("/api/products/{$id}")->json('previews.0.proxy');

        $this->assertSame("/api/products/{$id}/proxy/obj", $after['url']);
        $this->assertSame(['before' => 900, 'after' => 90], $after['triangles']);
    }

    /**
     * The whole reason the proxy exists is that the model does not leave the
     * server. Nothing but the proxy may be reachable through this route.
     */
    public function test_the_route_will_not_serve_the_model_itself(): void
    {
        $id = $this->publish(['proxy_mode' => 'model', 'proxy_ratio' => '0.1']);
        $source = Product::findOrFail($id)->deliverableFor('obj');

        $this->getJson("/api/products/{$id}/proxy/obj")->assertStatus(404);

        Storage::disk($source->disk)->assertExists($source->path);
    }

    /** The sandbox is argv, so nothing else in the suite would notice it going. */
    public function test_the_simplifier_runs_confined(): void
    {
        $argv = (new ProxyRunner())->commandFor('/s/model', '/s/job.json', '/s/out');

        $this->assertContains('--network=none', $argv);
        $this->assertContains('--cap-drop=ALL', $argv);
        $this->assertContains('--pids-limit=256', $argv);
        $this->assertNotContains('--privileged', $argv);

        // Its own entrypoint: the render path is what an attested still is
        // reproduced from and must not be reachable from here.
        $this->assertContains('--entrypoint', $argv);
        $this->assertContains('/app/proxy.mjs', $argv);

        // The model goes in read-only.
        $this->assertTrue(
            collect($argv)->contains(fn ($one) => str_ends_with((string) $one, ':/in/model:ro')),
            'the model is not mounted read-only'
        );
    }

    private function giveItAProxy(int $productId): ProductFile
    {
        $source = Product::findOrFail($productId)->deliverableFor('obj');
        $path = 'proxies/'.$productId.'-obj-abc123.glb';

        Storage::disk($source->disk)->put($path, 'glb-bytes');

        return $source->product->files()->create([
            'source_file_id' => $source->id,
            'kind' => ProductFile::KIND_PROXY,
            'format' => 'glb',
            'disk' => $source->disk,
            'path' => $path,
            'sort' => 0,
            'bytes' => 9,
            'checksum' => hash('sha256', 'glb-bytes'),
            'meta' => ['ratio' => 0.1, 'triangles' => ['before' => 900, 'after' => 90]],
        ]);
    }

    private function seller(): User
    {
        return User::create([
            'name' => 'seller'.uniqid(),
            'email' => uniqid().'@example.com',
            'password' => 'password123',
        ]);
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'name' => 'Half-track',
            'price' => '24.50',
            'objModel' => UploadedFile::fake()->createWithContent('model.obj', "v 0 0 0\n"),
            'thumbnails' => [UploadedFile::fake()->image('thumb.png')],
        ], $extra);
    }

    private function publish(array $extra = []): int
    {
        Sanctum::actingAs($this->seller());

        return $this->postJson('/api/products', $this->payload($extra))
            ->assertSuccessful()
            ->json('id');
    }
}
