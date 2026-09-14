<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * The promise is that a stranger cannot obtain the file that is for sale.
 *
 * Asserting that one flag produces one status code has missed this three times,
 * because the bug was never the guard — it was a writer that left the flag wrong.
 * So this asks the question the promise actually makes: walk every public GET
 * route, in every preview mode, and check whether any of them returns the bytes.
 */
class NoPublicRouteServesTheModelTest extends TestCase
{
    use RefreshDatabase;

    private const MODEL_BYTES = 'PK-this-is-the-paid-for-mesh-and-must-never-be-served';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('models');
        Storage::fake('public');
    }

    public static function previewModes(): array
    {
        return [
            'interactive' => [Product::PREVIEW_INTERACTIVE],
            'attested stills' => [Product::PREVIEW_ATTESTED_STILLS],
        ];
    }

    #[DataProvider('previewModes')]
    public function test_no_public_route_returns_the_deliverable(string $mode): void
    {
        $product = $this->productSellingAModel($mode);
        $checksum = hash('sha256', self::MODEL_BYTES);
        $served = [];

        foreach ($this->publicGetRoutes($product) as $uri) {
            $response = $this->get($uri);
            $body = $this->bodyOf($response);

            if (hash('sha256', $body) === $checksum || str_contains($body, self::MODEL_BYTES)) {
                $served[] = $uri.' → '.$response->status();
            }
        }

        $this->assertSame([], $served, "These public routes handed over the model in {$mode} mode: ".implode(', ', $served));
    }

    /** The same question for a product the seller has not published. */
    public function test_no_public_route_returns_a_draft(): void
    {
        $product = $this->productSellingAModel(Product::PREVIEW_INTERACTIVE);
        $product->update(['unlisted' => true, 'published_at' => null]);

        $leaked = [];

        foreach ($this->publicGetRoutes($product) as $uri) {
            $response = $this->get($uri);
            $body = $this->bodyOf($response);

            if (str_contains($body, self::MODEL_BYTES)) {
                $leaked[] = $uri;
            }
        }

        $this->assertSame([], $leaked, 'These routes handed over an unpublished draft: '.implode(', ', $leaked));
    }

    /** Works for a streamed file and an ordinary JSON response alike. */
    private function bodyOf($response): string
    {
        $base = $response->baseResponse;

        if ($base instanceof StreamedResponse) {
            ob_start();
            $base->sendContent();

            return (string) ob_get_clean();
        }

        return (string) $response->getContent();
    }

    /**
     * Every GET the router exposes without auth, with this product's id and
     * format filled in. Taken from the route table rather than a list here, so
     * a new public route is covered the day it is added.
     *
     * @return list<string>
     */
    private function publicGetRoutes(Product $product): array
    {
        $uris = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            if (array_intersect($middleware, ['auth:sanctum', 'auth', 'signed'])) {
                continue;
            }

            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/products/')) {
                continue;
            }

            $filled = str_replace(
                ['{id}', '{format}', '{file}', '{view}'],
                [(string) $product->id, 'obj', (string) $product->files->first()?->id, '1'],
                $uri
            );

            if (str_contains($filled, '{')) {
                continue;
            }

            $uris[] = '/'.$filled;
        }

        $this->assertNotEmpty($uris, 'No public product routes were found to check.');

        return $uris;
    }

    private function productSellingAModel(string $mode): Product
    {
        $seller = User::factory()->create();

        $product = Product::create([
            'name' => 'A model someone is selling',
            'description' => 'Not yours until you pay.',
            'price_cents' => 4500,
            'currency' => 'USD',
            'user_id' => $seller->id,
            'preview_mode' => $mode,
            'published_at' => now(),
        ]);

        Storage::disk('models')->put('obj_files/secret.obj', self::MODEL_BYTES);

        ProductFile::create([
            'product_id' => $product->id,
            'kind' => ProductFile::KIND_DELIVERABLE,
            'format' => 'obj',
            'disk' => 'models',
            'path' => 'obj_files/secret.obj',
            'sort' => 0,
            'bytes' => strlen(self::MODEL_BYTES),
            'checksum' => hash('sha256', self::MODEL_BYTES),
            'meta' => ['faces' => 12, 'sniffed_format' => 'obj'],
        ]);

        return $product->refresh()->load('files');
    }
}
