<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Jobs\RenderClipPreview;
use App\Models\Product;
use App\Support\AnimateRunner;
use App\Support\ModelFormats;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ClipController extends BaseController
{
    public function store(Request $request, $id)
    {
        $product = Product::with('files')->findOrFail($id);

        // The seller aims this camera, not a buyer: a clip is part of the
        // listing, the way the still pictures are.
        if ((int) $product->user_id !== (int) Auth::user()->getAuthIdentifier()) {
            abort(403, 'You are not the owner of this product!');
        }

        $fields = $request->validate([
            'format' => 'required|in:'.ModelFormats::rule(),
            'clip' => 'required|integer|min:0|max:63',
            'passes' => 'sometimes|array|min:1',
            'passes.*' => 'in:'.implode(',', AnimateRunner::PASSES),
            'frames' => 'sometimes|integer|between:8,60',
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

        // obj and stl carry no animation at all, and a glTF without one would
        // only fail in the container several minutes later.
        if (($source->facts()['animated'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'clip' => "The .{$fields['format']} file has no animation in it.",
            ]);
        }

        RenderClipPreview::dispatch(
            $source->id,
            (int) $fields['clip'],
            $fields['camera'] + ['up' => [0, 1, 0]],
            $fields['passes'] ?? AnimateRunner::PASSES,
            (int) ($fields['frames'] ?? 24),
        )->afterCommit();

        return (new ProductResource($product->fresh()->load('files')))
            ->response()
            ->setStatusCode(202);
    }
}
