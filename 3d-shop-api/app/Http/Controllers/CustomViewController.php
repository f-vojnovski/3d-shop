<?php

namespace App\Http\Controllers;

use App\Jobs\RenderCustomView;
use App\Jobs\RenderProductPreviews;
use App\Http\Resources\ProductResource;
use App\Models\CustomView;
use App\Models\Product;
use App\Support\ModelFormats;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CustomViewController extends BaseController
{
    /** Mirrors the cap the upload validation puts on a format's angles. */
    private const MAX_ANGLES = 8;

    /**
     * Takes a camera and draws the model from it. The viewer never receives the
     * mesh: they aim at a box the size of the measured bounding box, and the
     * server renders the file.
     */
    public function store(Request $request, $id)
    {
        $product = Product::with('files')->findOrFail($id);

        $fields = $request->validate([
            'format' => 'required|in:'.ModelFormats::rule(),
            'pass' => 'required|in:'.implode(',', CustomView::PASSES),
            'camera.position' => 'required|array|size:3',
            'camera.position.*' => 'required|numeric|between:-1000,1000',
            'camera.target' => 'required|array|size:3',
            'camera.target.*' => 'required|numeric|between:-1000,1000',
            'camera.up' => 'sometimes|array|size:3',
            'camera.up.*' => 'numeric|between:-1,1',
            'camera.fov' => 'required|numeric|between:10,120',
        ]);

        $source = $product->deliverableFor($fields['format']);

        if ($source === null) {
            throw ValidationException::withMessages([
                'format' => "This product has no .{$fields['format']} file.",
            ]);
        }

        if ($source->stills()->count() === 0) {
            throw ValidationException::withMessages([
                'format' => 'This file has no server-rendered views yet.',
            ]);
        }

        if (in_array($fields['pass'], CustomView::NEEDS_UVS, true) && ! ($source->facts()['uvs'] ?? false)) {
            throw ValidationException::withMessages([
                'pass' => "The .{$fields['format']} file has no UVs, so a checker view would be one flat colour.",
            ]);
        }

        $camera = $fields['camera'] + ['up' => [0, 1, 0]];
        $fingerprint = CustomView::fingerprintOf($camera, $fields['pass']);
        $viewerId = (int) Auth::user()->getAuthIdentifier();

        $view = CustomView::firstOrCreate(
            [
                'product_file_id' => $source->id,
                'user_id' => $viewerId,
                'fingerprint' => $fingerprint,
            ],
            [
                'product_id' => $product->id,
                'pass' => $fields['pass'],
                'status' => CustomView::QUEUED,
                'camera' => $camera,
                'expires_at' => now()->addHours(CustomView::LIFETIME_HOURS),
            ]
        );

        // A repeat of a failure is worth another try; of a drawn view it is not.
        if ($view->status === CustomView::FAILED) {
            $view->update([
                'status' => CustomView::QUEUED,
                'failure_reason' => null,
                'expires_at' => now()->addHours(CustomView::LIFETIME_HOURS),
            ]);
        }

        if ($view->wasRecentlyCreated || $view->status === CustomView::QUEUED) {
            RenderCustomView::dispatch($view->id)->afterCommit();
        }

        return $this->describe($view->refresh());
    }

    /**
     * The camera travels, not the picture: a requested view carries no image
     * checksum and no renderer identity, so re-filing it as a still would put an
     * unattested image in the attested gallery. Re-rendering is also the only
     * way to add an angle after publishing.
     */
    public function publish(Request $request, $id, $viewId)
    {
        $product = Product::with('files')->findOrFail($id);

        if ((int) $product->user_id !== (int) Auth::user()->getAuthIdentifier()) {
            abort(403, 'You are not the owner of this product!');
        }

        $view = CustomView::where('product_id', $product->id)
            ->where('user_id', Auth::user()->getAuthIdentifier())
            ->findOrFail($viewId);

        if ($view->status !== CustomView::READY) {
            throw ValidationException::withMessages([
                'view' => 'That view has not been drawn yet.',
            ]);
        }

        $source = $product->files->firstWhere('id', $view->product_file_id);

        if ($source === null || $source->isSuperseded()) {
            throw ValidationException::withMessages([
                'view' => 'That view was drawn from a file this product no longer sells.',
            ]);
        }

        $angles = $source->angles();

        if (count($angles) >= self::MAX_ANGLES) {
            throw ValidationException::withMessages([
                'view' => sprintf('This file already has %d angles, which is the most a listing shows.', self::MAX_ANGLES),
            ]);
        }

        $camera = $view->camera;

        foreach ($angles as $existing) {
            if (CustomView::fingerprintOf($existing + ['up' => [0, 1, 0]], $view->pass) === $view->fingerprint) {
                throw ValidationException::withMessages([
                    'view' => 'That camera is already on the listing.',
                ]);
            }
        }

        $angles[] = [
            'position' => $camera['position'],
            'target' => $camera['target'],
            'up' => $camera['up'] ?? [0, 1, 0],
            'fov' => $camera['fov'],
            'origin' => 'requested',
            'slot' => count($angles),
        ];

        $source->withMeta([
            'angles' => $angles,
            'render' => ['status' => 'queued', 'error' => null],
        ]);

        $product->refreshPreviewStatus();
        RenderProductPreviews::dispatch($product->id, $source->format)->afterCommit();

        return new ProductResource($product->fresh()->load('files'));
    }

    /** @return array<string, mixed> */
    public function index(Request $request, $id)
    {
        $views = CustomView::where('product_id', $id)
            ->where('user_id', Auth::user()->getAuthIdentifier())
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->limit(24)
            ->get();

        return ['views' => $views->map(fn (CustomView $view) => $this->describe($view))->all()];
    }

    /** @return array<string, mixed> */
    private function describe(CustomView $view): array
    {
        return [
            'id' => $view->id,
            'format' => $view->source?->format,
            'pass' => $view->pass,
            'status' => $view->status,
            'camera' => $view->camera,
            'url' => $view->url(),
            'expires_at' => $view->expires_at,
            'error' => $view->failure_reason,
        ];
    }
}
