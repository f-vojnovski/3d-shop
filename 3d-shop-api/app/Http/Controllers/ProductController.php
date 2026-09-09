<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Jobs\RenderProductPreviews;
use App\Models\ProductFile;
use App\Support\MeshPrescan;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends BaseController
{
    private const DELIVERABLE_DISK = 'models';

    private const ANGLE_RULES = [
        'preview_angles' => 'sometimes|nullable|array|max:8',
        'preview_angles.*.position' => 'required|array|size:3',
        'preview_angles.*.position.*' => 'required|numeric',
        'preview_angles.*.target' => 'required|array|size:3',
        'preview_angles.*.target.*' => 'required|numeric',
        'preview_angles.*.fov' => 'required|numeric|min:1|max:179',
    ];

    public function index()
    {
        return ProductResource::collection(
            Product::with('files')->where('unlisted', false)->orderBy('id')->paginate(16)
        );
    }

    public function store(Request $request)
    {
        $this->decodeAngles($request);

        $request->validate([
            'name' => 'required|max:255',
            'description' => 'nullable|max:1000',
            'price' => 'required|numeric|min:0|max:999999.99',
            'preview_mode' => 'sometimes|in:interactive,attested_stills',
            'objModel' => 'nullable|file|max:51200',
            'gltfModel' => 'nullable|file|max:51200',
            'thumbnail' => 'required|file|image|max:5120',
            ...self::ANGLE_RULES,
        ]);

        $models = array_filter([
            'obj' => $request->file('objModel'),
            'gltf' => $request->file('gltfModel'),
        ]);

        if (empty($models)) {
            abort(422, 'At least one model file is required.');
        }

        return DB::transaction(function () use ($request, $models) {
            $product = Product::create([
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'price_cents' => (int) round($request->input('price') * 100),
                'preview_mode' => $request->input('preview_mode', Product::PREVIEW_INTERACTIVE),
                'preview_angles' => $request->input('preview_angles'),
                'user_id' => Auth::user()->getAuthIdentifier(),
            ]);

            foreach ($models as $format => $file) {
                $this->storeFile($product, $file, ProductFile::KIND_DELIVERABLE, self::DELIVERABLE_DISK, $format);
            }

            $this->storeFile($product, $request->file('thumbnail'), ProductFile::KIND_THUMBNAIL, 'public');

            if ($product->preview_mode === Product::PREVIEW_ATTESTED_STILLS) {
                RenderProductPreviews::dispatch($product->id)->afterCommit();
            }

            return new ProductResource($product->load('files'));
        });
    }

    public function show($id)
    {
        return new ProductResource(Product::with('files')->findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $this->decodeAngles($request);

        $product = Product::findOrFail($id);

        if ($product->user_id != Auth::user()->getAuthIdentifier()) {
            abort(403, 'You are not the owner of this product!');
        }

        $fields = $request->validate([
            'name' => 'sometimes|required|max:255',
            'description' => 'sometimes|nullable|max:1000',
            'price' => 'sometimes|required|numeric|min:0|max:999999.99',
            'preview_mode' => 'sometimes|in:interactive,attested_stills',
            'unlisted' => 'sometimes|boolean',
            ...self::ANGLE_RULES,
        ]);

        if (array_key_exists('price', $fields)) {
            $fields['price_cents'] = (int) round($fields['price'] * 100);
            unset($fields['price']);
        }

        $product->update($fields);

        if (array_key_exists('preview_angles', $fields) || array_key_exists('preview_mode', $fields)) {
            RenderProductPreviews::dispatch($product->id);
        }

        return new ProductResource($product->load('files'));
    }

    /**
     * Public, but only while the seller has chosen to expose the geometry.
     */
    public function previewModel($id, string $format): StreamedResponse
    {
        $product = Product::with('files')->findOrFail($id);

        if (! $product->servesInteractivePreview()) {
            abort(403, 'This product does not offer an interactive preview.');
        }

        return $this->streamDeliverable($product, $format, inline: true);
    }

    /**
     * The gate: geometry leaves the server only for an owner or a buyer.
     */
    public function download(Request $request, $id, string $format): StreamedResponse
    {
        $product = Product::with('files')->findOrFail($id);
        $userId = (int) $request->query('user');

        if (! $product->isDownloadableBy($userId)) {
            abort(403, 'You have not purchased this product.');
        }

        return $this->streamDeliverable($product, $format, inline: false);
    }

    public function search($name)
    {
        return ProductResource::collection(
            Product::with('files')
                ->where('unlisted', false)
                ->where('name', 'like', '%'.$name.'%')
                ->get()
        );
    }

    public function getCurrentUserProducts()
    {
        return ProductResource::collection(
            Product::with('files')
                ->where('user_id', Auth::user()->getAuthIdentifier())
                ->orderBy('id')
                ->paginate(16)
        );
    }

    public function getProductsForUser($userId)
    {
        return ProductResource::collection(
            Product::with('files')
                ->where('unlisted', false)
                ->where('user_id', $userId)
                ->orderBy('id')
                ->paginate(16)
        );
    }

    public function getPurchasedProductsForUser()
    {
        $userId = Auth::user()->getAuthIdentifier();

        return ProductResource::collection(
            Product::with('files')
                ->whereHas('sales', fn ($query) => $query->where('buyer_id', $userId))
                ->orderBy('id')
                ->paginate(16)
        );
    }

    private function decodeAngles(Request $request): void
    {
        $angles = $request->input('preview_angles');

        if (is_string($angles)) {
            $request->merge(['preview_angles' => json_decode($angles, true) ?? []]);
        }
    }

    private function storeFile(
        Product $product,
        UploadedFile $file,
        string $kind,
        string $disk,
        ?string $format = null
    ): ProductFile {
        $directory = match ($kind) {
            ProductFile::KIND_THUMBNAIL => 'thumbnails',
            default => $format.'_files',
        };

        // Measured here, while the upload is still on local disk. Once it is in
        // object storage a prescan would mean transferring it back.
        $scan = $kind === ProductFile::KIND_DELIVERABLE
            ? MeshPrescan::of($file->getRealPath())
            : null;

        $checksum = hash_file('sha256', $file->getRealPath());
        $bytes = $file->getSize();

        $name = uniqid().'.'.$file->getClientOriginalExtension();
        $path = Storage::disk($disk)->putFileAs($directory, $file, $name);

        return $product->files()->create([
            'kind' => $kind,
            'format' => $format,
            'disk' => $disk,
            'path' => $path,
            'bytes' => $bytes,
            'checksum' => $checksum,
            'meta' => $scan === null ? null : [
                'sniffed_format' => $scan->format,
                'triangles' => $scan->triangles,
            ],
        ]);
    }

    private function streamDeliverable(Product $product, string $format, bool $inline): StreamedResponse
    {
        $file = $product->deliverableFor($format);

        if ($file === null) {
            abort(404, 'That format is not available for this product.');
        }

        return Storage::disk($file->disk)->response(
            $file->path,
            basename($file->path),
            [],
            $inline ? 'inline' : 'attachment'
        );
    }
}
