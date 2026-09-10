<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Jobs\RenderProductPreviews;
use App\Models\ProductFile;
use App\Support\MeshFacts;
use App\Support\MeshPrescan;
use App\Support\StandardAngles;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends BaseController
{
    private const DELIVERABLE_DISK = 'models';

    // Angles arrive keyed by model format: {"obj": [...], "gltf": [...]}.
    private const ANGLE_RULES = [
        'preview_angles' => 'sometimes|nullable|array',
        'preview_angles.*' => 'array|max:8',
        'preview_angles.*.*.position' => 'required|array|size:3',
        'preview_angles.*.*.position.*' => 'required|numeric',
        'preview_angles.*.*.target' => 'required|array|size:3',
        'preview_angles.*.*.target.*' => 'required|numeric',
        'preview_angles.*.*.fov' => 'required|numeric|min:1|max:179',
    ];

    public function index()
    {
        return ProductResource::collection(
            Product::with('files')->forViewer(Auth::id())->where('unlisted', false)->orderBy('id')->paginate(16)
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
            'objModel' => 'nullable|file|max:51200|extensions:obj',
            'gltfModel' => 'nullable|file|max:51200|extensions:gltf,glb',
            'thumbnail' => 'nullable|file|image|mimes:jpeg,png,webp|max:5120',
            'images' => 'sometimes|array|max:8',
            'images.*' => 'file|image|mimes:jpeg,png,webp|max:5120',
            'standard_views' => 'sometimes|array',
            'standard_views.*' => 'in:obj,gltf',
            ...self::ANGLE_RULES,
        ]);

        $angles = $request->input('preview_angles') ?? [];
        $standard = $request->input('standard_views') ?? [];

        $models = array_filter([
            'obj' => $request->file('objModel'),
            'gltf' => $request->file('gltfModel'),
        ]);

        if ($models === []) {
            abort(422, 'At least one model file is required.');
        }

        $this->guardAnglesForMode(
            $request->input('preview_mode', Product::PREVIEW_INTERACTIVE),
            array_keys($models),
            $angles,
            $standard
        );

        // The seller's own shots come first: they chose those, and view 1 is
        // what a buyer sees before touching anything.
        foreach ($standard as $format) {
            if (isset($models[$format])) {
                $angles[$format] = array_merge($angles[$format] ?? [], StandardAngles::set());
            }
        }

        // Only a render can stand in for a missing thumbnail, so a listing that
        // will not produce one has to arrive with its own.
        if ($request->file('thumbnail') === null && $standard === []) {
            throw ValidationException::withMessages([
                'thumbnail' => 'Pick a thumbnail, or turn on the standard views and the first one will be used.',
            ]);
        }

        $scans = $this->scanModels($models);

        // Outside the transaction: this reads every byte of every model, and a
        // 25 MB .obj takes about a fifth of a second.
        $facts = array_map(
            fn (UploadedFile $file, string $format) => MeshFacts::of($file->getRealPath(), $scans[$format]->format),
            $models,
            array_keys($models)
        );
        $facts = array_combine(array_keys($models), $facts);

        return DB::transaction(function () use ($request, $models, $angles, $scans, $facts) {
            $product = Product::create([
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'price_cents' => (int) round($request->input('price') * 100),
                'preview_mode' => $request->input('preview_mode', Product::PREVIEW_INTERACTIVE),
                'user_id' => Auth::user()->getAuthIdentifier(),
            ]);

            foreach ($models as $format => $file) {
                $this->storeFile(
                    $product,
                    $file,
                    ProductFile::KIND_DELIVERABLE,
                    self::DELIVERABLE_DISK,
                    $format,
                    $angles[$format] ?? [],
                    scan: $scans[$format],
                    facts: $facts[$format]
                );
            }

            if ($request->file('thumbnail') !== null) {
                $this->storeFile($product, $request->file('thumbnail'), ProductFile::KIND_THUMBNAIL, 'public');
            }

            foreach (array_values($request->file('images') ?? []) as $sort => $image) {
                $this->storeFile(
                    $product,
                    $image,
                    ProductFile::KIND_SELLER_IMAGE,
                    'public',
                    sort: $sort
                );
            }

            if ($product->preview_mode === Product::PREVIEW_ATTESTED_STILLS) {
                foreach (array_keys($models) as $format) {
                    RenderProductPreviews::dispatch($product->id, $format)->afterCommit();
                }
            }

            return new ProductResource($product->load('files'));
        });
    }

    public function show($id)
    {
        return new ProductResource(
            Product::with('files')->forViewer(Auth::id())->findOrFail($id)
        );
    }

    /** Name, description and price only. Files change through replace(). */
    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        if ($product->user_id != Auth::user()->getAuthIdentifier()) {
            abort(403, 'You are not the owner of this product!');
        }

        $this->refuseSettledFields($request);

        if (in_array($product->preview_status, ['queued', 'rendering'], true)) {
            throw ValidationException::withMessages([
                'name' => 'This product is still rendering. Try again when it has finished.',
            ]);
        }

        $fields = $request->validate([
            'name' => 'sometimes|required|max:255',
            'description' => 'sometimes|nullable|max:1000',
            'price' => 'sometimes|required|numeric|min:0|max:999999.99',
            'unlisted' => 'sometimes|boolean',
        ]);

        if (array_key_exists('price', $fields)) {
            $fields['price_cents'] = (int) round($fields['price'] * 100);
            unset($fields['price']);
        }

        $product->update($fields);

        return new ProductResource($product->load('files'));
    }

    /**
     * Swaps one model file, re-renders its previews, and keeps the old file and
     * previews as superseded so their attestation records still resolve.
     */
    public function replace(Request $request, $id)
    {
        $product = Product::with('files')->findOrFail($id);

        if ($product->user_id != Auth::user()->getAuthIdentifier()) {
            abort(403, 'You are not the owner of this product!');
        }

        $fields = $request->validate([
            'format' => 'required|in:obj,gltf',
            'model' => 'required|file|max:51200',
            'note' => 'nullable|string|max:200',
        ]);

        $current = $product->deliverableFor($fields['format']);

        if ($current === null) {
            throw ValidationException::withMessages([
                'format' => "This product has no .{$fields['format']} file to replace.",
            ]);
        }

        // A render in flight would leave the pictures and the file disagreeing.
        if (in_array($product->preview_status, ['queued', 'rendering'], true)) {
            throw ValidationException::withMessages([
                'model' => 'This product is still rendering. Try again when it has finished.',
            ]);
        }

        $file = $request->file('model');
        $scan = $this->scanModels([$fields['format'] => $file])[$fields['format']];
        $facts = MeshFacts::of($file->getRealPath(), $scan->format);

        return DB::transaction(function () use ($product, $current, $file, $fields, $scan, $facts) {
            // Cameras belong to the listing, so the views stay comparable.
            $replacement = $this->storeFile(
                $product,
                $file,
                ProductFile::KIND_DELIVERABLE,
                self::DELIVERABLE_DISK,
                $fields['format'],
                $current->angles(),
                scan: $scan,
                facts: $facts
            );

            $supersede = [
                'superseded_at' => now(),
                'superseded_by_id' => $replacement->id,
            ];

            $current->stills()->update($supersede);
            $current->wireframes()->update($supersede);

            $current->update([
                'superseded_at' => now(),
                'superseded_by_id' => $replacement->id,
                'replacement_note' => $fields['note'] ?? null,
            ]);

            RenderProductPreviews::dispatch($product->id, $fields['format'])->afterCommit();

            return new ProductResource($product->load('files'));
        });
    }

    /**
     * Withdraws a listing instead of deleting it: a buyer keeps what they paid
     * for, so the rows and files have to stay.
     */
    public function destroy($id)
    {
        $product = Product::findOrFail($id);

        if ($product->user_id != Auth::user()->getAuthIdentifier()) {
            abort(403, 'You are not the owner of this product!');
        }

        $product->update(['unlisted' => true]);

        return response()->noContent();
    }

    public function previewModel($id, string $format): StreamedResponse
    {
        $product = Product::with('files')->findOrFail($id);

        if (! $product->servesInteractivePreview()) {
            abort(403, 'This product does not offer an interactive preview.');
        }

        return $this->streamDeliverable($product, $format, inline: true);
    }

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
                ->forViewer(Auth::id())
                ->where('unlisted', false)
                ->where('name', 'like', '%'.$name.'%')
                ->get()
        );
    }

    public function getCurrentUserProducts()
    {
        return ProductResource::collection(
            Product::with('files')
                ->forViewer(Auth::id())
                ->where('user_id', Auth::id())
                ->orderBy('id')
                ->paginate(16)
        );
    }

    public function getProductsForUser($userId)
    {
        return ProductResource::collection(
            Product::with('files')
                ->forViewer(Auth::id())
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
                ->forViewer($userId)
                ->whereHas('sales', fn ($query) => $query->where('buyer_id', $userId))
                ->orderBy('id')
                ->paginate(16)
        );
    }

    /**
     * Every format a buyer can pick needs an angle, or that tab shows an empty
     * gallery with nothing to explain it.
     */
    private function guardAnglesForMode(string $mode, array $formats, array $angles, array $standard): void
    {
        if ($mode !== Product::PREVIEW_ATTESTED_STILLS) {
            return;
        }

        foreach ($formats as $format) {
            if (($angles[$format] ?? []) === [] && ! in_array($format, $standard, true)) {
                throw ValidationException::withMessages([
                    "preview_angles.{$format}" => "Capture at least one camera angle for the .{$format} file, or ask for the standard views.",
                ]);
            }
        }
    }

    /**
     * @param  array<string, UploadedFile>  $models
     * @return array<string, MeshPrescan>
     */
    private function scanModels(array $models): array
    {
        $fields = ['obj' => 'objModel', 'gltf' => 'gltfModel'];
        $accepted = ['obj' => ['obj'], 'gltf' => ['gltf', 'glb']];
        $scans = [];

        foreach ($models as $format => $file) {
            $scan = MeshPrescan::of($file->getRealPath());

            if ($rejection = $scan->rejection()) {
                throw ValidationException::withMessages([$fields[$format] => $rejection]);
            }

            // The extension is the uploader's word; the magic bytes are not.
            if (! in_array($scan->format, $accepted[$format], true)) {
                throw ValidationException::withMessages([
                    $fields[$format] => sprintf(
                        'That file is a .%s, not a .%s.',
                        $scan->format,
                        $format
                    ),
                ]);
            }

            $scans[$format] = $scan;
        }

        return $scans;
    }

    private function refuseSettledFields(Request $request): void
    {
        $settled = array_values(array_filter(
            ['preview_angles', 'preview_mode', 'objModel', 'gltfModel', 'thumbnail', 'images'],
            fn (string $field) => $request->has($field) || $request->hasFile($field)
        ));

        if ($settled === []) {
            return;
        }

        throw ValidationException::withMessages([
            $settled[0] => 'Previews are settled when a product is published. Upload a new product to change them.',
        ]);
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
        ?string $format = null,
        array $angles = [],
        int $sort = 0,
        ?MeshPrescan $scan = null,
        ?MeshFacts $facts = null
    ): ProductFile {
        $directory = match ($kind) {
            ProductFile::KIND_THUMBNAIL => 'thumbnails',
            ProductFile::KIND_SELLER_IMAGE => 'seller_images',
            default => $format.'_files',
        };

        $checksum = hash_file('sha256', $file->getRealPath());
        $bytes = $file->getSize();

        // Derived here, never from the client's filename: a real PNG called
        // `x.php` passes image validation and would land on the public disk
        // under a name a web server may hand to an interpreter.
        $extension = $scan === null ? $file->extension() : $scan->format;
        $name = uniqid().($extension === '' ? '' : '.'.$extension);
        $path = Storage::disk($disk)->putFileAs($directory, $file, $name);

        return $product->files()->create([
            'kind' => $kind,
            'format' => $format,
            'disk' => $disk,
            'path' => $path,
            'sort' => $sort,
            'bytes' => $bytes,
            'checksum' => $checksum,
            'meta' => $scan === null ? null : [
                'sniffed_format' => $scan->format,
                'faces' => $scan->faces,
                'facts' => $facts?->toArray(),
                'angles' => $angles,
                'render' => ['status' => $angles === [] ? 'none' : 'queued', 'error' => null],
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
