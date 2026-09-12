<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Jobs\BuildViewerProxy;
use App\Jobs\RenderProductPreviews;
use App\Jobs\ScaleThumbnail;
use App\Models\ProductFile;
use App\Support\MeshPrescan;
use App\Support\ModelConverter;
use App\Support\ModelFormats;
use App\Support\ModelUpload;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends BaseController
{
    private const THUMBNAIL_SOURCES = [
        ProductFile::KIND_PREVIEW_IMAGE,
        ProductFile::KIND_SELLER_IMAGE,
    ];

    private const DELIVERABLE_DISK = 'models';

    // Angles arrive keyed by model format: {"obj": [...], "gltf": [...]}.
    private const ANGLE_RULES = [
        'preview_angles' => 'sometimes|nullable|array',
        'preview_angles.*' => 'array|max:8',
        'preview_angles.*.*.position' => 'required|array|size:3',
        'preview_angles.*.*.position.*' => 'required|numeric',
        'preview_angles.*.*.target' => 'required|array|size:3',
        'preview_angles.*.*.target.*' => 'required|numeric',
        // The harness reads `up` whether or not anyone validated it.
        'preview_angles.*.*.up' => 'sometimes|array|size:3',
        'preview_angles.*.*.up.*' => 'numeric|between:-1,1',
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
            // Off by default: a listing goes up when the seller says so, not
            // while its pictures are still being rendered.
            'publish' => 'sometimes|boolean',
            // What a buyer aims a camera at: a copy cut down to `proxy_ratio`,
            // or nothing but the bounding box.
            'proxy_mode' => 'sometimes|in:model,box',
            'proxy_ratio' => 'sometimes|numeric|between:0,1',
            // How hard the cutter may work: careful never removes a whole
            // piece, parts may, which is the only way a model built from many
            // small pieces reaches the ratio at all.
            'proxy_method' => 'sometimes|in:careful,parts',
            ...self::modelRules(),
            'thumbnails' => 'sometimes|array|max:8',
            'thumbnails.*' => 'file|image|mimes:jpeg,png,webp|max:5120',
            'images' => 'sometimes|array|max:8',
            'images.*' => 'file|image|mimes:jpeg,png,webp|max:5120',
            ...self::ANGLE_RULES,
        ]);

        $angles = $request->input('preview_angles') ?? [];

        $models = array_filter(array_map(
            fn (string $field) => $request->file($field),
            ModelFormats::fields()
        ));

        if ($models === []) {
            abort(422, 'At least one model file is required.');
        }

        $this->guardAnglesForMode(
            $request->input('preview_mode', Product::PREVIEW_INTERACTIVE),
            array_keys($models),
            $angles
        );

        // Only a render can stand in for a missing thumbnail, so a listing that
        // will not produce one has to arrive with its own.
        if (($request->file('thumbnails') ?? []) === [] && $angles === []) {
            throw ValidationException::withMessages([
                'thumbnails' => 'Pick a thumbnail, or frame a camera angle and the first render will be used.',
            ]);
        }

        // Outside the transaction: this reads every byte of every model, and a
        // 25 MB .obj takes about a fifth of a second.
        $uploads = $this->readModels($models);

        return DB::transaction(function () use ($request, $models, $angles, $uploads) {
            $product = Product::create([
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'price_cents' => (int) round($request->input('price') * 100),
                'preview_mode' => $request->input('preview_mode', Product::PREVIEW_INTERACTIVE),
                'user_id' => Auth::user()->getAuthIdentifier(),
                'unlisted' => ! $request->boolean('publish'),
                'published_at' => $request->boolean('publish') ? now() : null,
            ]);

            foreach ($models as $format => $file) {
                $this->storeFile(
                    $product,
                    $file,
                    ProductFile::KIND_DELIVERABLE,
                    self::DELIVERABLE_DISK,
                    $format,
                    $angles[$format] ?? [],
                    upload: $uploads[$format]
                );
            }

            foreach (array_values($request->file('thumbnails') ?? []) as $sort => $picture) {
                $thumbnail = $this->storeFile(
                    $product, $picture, ProductFile::KIND_THUMBNAIL, 'public', sort: $sort
                );
                ScaleThumbnail::dispatch($thumbnail->id)->afterCommit();
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

            $proxy = [
                'mode' => $request->input('proxy_mode', 'box'),
                'ratio' => (float) $request->input('proxy_ratio', 0.1),
                'method' => $request->input('proxy_method', 'careful'),
            ];

            foreach ($product->files as $file) {
                if ($file->kind !== ProductFile::KIND_DELIVERABLE) {
                    continue;
                }

                $file->withMeta(['proxy' => $proxy]);
                BuildViewerProxy::dispatch($file->id)->afterCommit();
            }

            if ($product->preview_mode === Product::PREVIEW_ATTESTED_STILLS) {
                foreach (array_keys($models) as $format) {
                    RenderProductPreviews::dispatch($product->id, $format)->afterCommit();
                }
            }

            return new ProductResource($product->load('files'));
        });
    }

    /**
     * Hands back a browser-readable copy of a format no browser can open, so
     * the seller can frame their own angles instead of being given eight.
     *
     * The copy is the one the renderer will make for itself later: the
     * conversion is deterministic for a given file and image, so the camera the
     * seller aims here lands where they aimed it.
     */
    public function convert(Request $request, ModelConverter $converter)
    {
        $request->validate(['model' => 'required|file|max:51200']);

        $scan = MeshPrescan::of($request->file('model')->getRealPath());

        if (! ModelConverter::needsConverting($scan->format)) {
            throw ValidationException::withMessages([
                'model' => "A .{$scan->format} opens in the browser as it is.",
            ]);
        }

        if ($refusal = $scan->rejection()) {
            throw ValidationException::withMessages(['model' => $refusal]);
        }

        $scratch = storage_path('app/private/convert/'.bin2hex(random_bytes(8)));

        try {
            File::ensureDirectoryExists($scratch, 0775, true);
            $result = $converter->toGlb($request->file('model')->getRealPath(), $scratch);

            if (($result['status'] ?? 'failed') !== 'ok') {
                throw ValidationException::withMessages([
                    'model' => $result['reason'] ?? 'That file could not be converted.',
                ]);
            }

            return response((string) file_get_contents($result['path']), 200, [
                'Content-Type' => 'model/gltf-binary',
                'Cache-Control' => 'no-store',
            ]);
        } finally {
            File::deleteDirectory($scratch);
        }
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
            'format' => 'required|in:'.ModelFormats::rule(),
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
        $upload = $this->readModels([$fields['format'] => $file])[$fields['format']];

        return DB::transaction(function () use ($product, $current, $file, $fields, $upload) {
            // Cameras belong to the listing, so the views stay comparable.
            $replacement = $this->storeFile(
                $product,
                $file,
                ProductFile::KIND_DELIVERABLE,
                self::DELIVERABLE_DISK,
                $fields['format'],
                $current->angles(),
                upload: $upload
            );

            $supersede = [
                'superseded_at' => now(),
                'superseded_by_id' => $replacement->id,
            ];

            $current->stills()->update($supersede);
            $current->wireframes()->update($supersede);
            $current->derived()->update($supersede);

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
     * Puts a listing on sale. Used both for the first publish and for putting a
     * withdrawn one back, because to a seller those are the same button.
     *
     * `published_at` is only stamped once: it says when buyers first saw this,
     * and withdrawing does not un-happen that.
     */
    public function publish($id)
    {
        $product = Product::with('files')->findOrFail($id);

        if ((int) $product->user_id !== (int) Auth::user()->getAuthIdentifier()) {
            abort(403, 'You are not the owner of this product!');
        }

        // A live listing with no picture is a blank card in the grid. Angles
        // turn into one once the render lands, so this is usually a matter of
        // waiting rather than of doing anything.
        if ($product->thumbnails()->count() === 0) {
            throw ValidationException::withMessages([
                'listing' => 'This listing has no picture yet. Wait for the render to finish, or add a thumbnail.',
            ]);
        }

        $product->update([
            'unlisted' => false,
            'published_at' => $product->published_at ?? now(),
        ]);

        return new ProductResource($product->fresh()->load('files'));
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

    /**
     * The cut-down copy a buyer aims a camera at. Public on purpose — aiming
     * happens before paying — but this route can only ever reach a proxy, and
     * the proxy holds only the detail the seller agreed to give away.
     */
    public function viewerProxy($id, string $format): StreamedResponse
    {
        $product = Product::with('files')->findOrFail($id);
        $proxy = $product->deliverableFor($format)?->proxy();

        if ($proxy === null) {
            abort(404, 'This product has no viewer proxy.');
        }

        return Storage::disk($proxy->disk)->response($proxy->path, 'proxy.glb', [
            'Content-Type' => 'model/gltf-binary',
            'Content-Disposition' => 'inline; filename="proxy.glb"',
        ]);
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

    /** Whoever may download the current file may fetch what it replaced. */
    public function downloadVersion(Request $request, $id, $file): StreamedResponse
    {
        $product = Product::with('files')->findOrFail($id);

        if (! $product->isDownloadableBy((int) $request->query('user'))) {
            abort(403, 'You have not purchased this product.');
        }

        $version = $product->files->first(
            fn (ProductFile $candidate) => (int) $candidate->getKey() === (int) $file
                && $candidate->kind === ProductFile::KIND_DELIVERABLE
        );

        if ($version === null) {
            abort(404, 'That version does not belong to this product.');
        }

        return $this->streamFile($version, inline: false);
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
    /**
     * A listing with no angle has nothing to show and no way to get anything,
     * so the seller frames at least one. What a buyer cannot see from those is
     * what the request-a-view button is for.
     */
    private function guardAnglesForMode(string $mode, array $formats, array $angles): void
    {
        if ($mode !== Product::PREVIEW_ATTESTED_STILLS) {
            return;
        }

        foreach ($formats as $format) {
            if (($angles[$format] ?? []) === []) {
                throw ValidationException::withMessages([
                    "preview_angles.{$format}" => "Frame at least one camera angle for the .{$format} file.",
                ]);
            }
        }
    }

    /** @return array<string, string> */
    private static function modelRules(): array
    {
        $rules = [];

        foreach (ModelFormats::all() as $format) {
            $rules[ModelFormats::field($format)] =
                'nullable|file|max:51200|extensions:'.ModelFormats::uploadExtensionRule($format);
        }

        return $rules;
    }

    /**
     * @param  array<string, UploadedFile>  $models
     * @return array<string, ModelUpload>
     */
    private function readModels(array $models): array
    {
        $fields = ModelFormats::fields();
        $uploads = [];

        foreach ($models as $format => $file) {
            $upload = ModelUpload::read($file->getRealPath(), $format);

            if (! $upload->accepted()) {
                throw ValidationException::withMessages([$fields[$format] => $upload->refusal]);
            }

            $uploads[$format] = $upload;
        }

        return $uploads;
    }

    private function refuseSettledFields(Request $request): void
    {
        $settled = array_values(array_filter(
            ['preview_angles', 'preview_mode', ...array_values(ModelFormats::fields()), 'thumbnail', 'images'],
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

    /**
     * Stored at full size and shrunk by a job, so the upload returns as soon as
     * the bytes are safe rather than waiting on image work.
     */
    public function addThumbnails(Request $request, $id)
    {
        $product = Product::with('files')->findOrFail($id);

        if ((int) $product->user_id !== (int) Auth::user()->getAuthIdentifier()) {
            abort(403, 'You are not the owner of this product!');
        }

        $request->validate([
            'images' => 'sometimes|array|max:8',
            'images.*' => 'file|image|mimes:jpeg,png,webp|max:5120',
            'from' => 'sometimes|array|max:8',
            'from.*' => 'integer',
        ]);

        $sort = (int) ($product->thumbnails->max('sort') ?? -1) + 1;
        $added = [];

        foreach ($request->file('images') ?? [] as $image) {
            $added[] = $this->storeFile(
                $product, $image, ProductFile::KIND_THUMBNAIL, 'public', sort: $sort++
            );
        }

        foreach ((array) $request->input('from', []) as $sourceId) {
            $source = $product->files->first(
                fn (ProductFile $file) => (int) $file->id === (int) $sourceId
                    && in_array($file->kind, self::THUMBNAIL_SOURCES, true)
                    && ! $file->isSuperseded()
            );

            if ($source === null) {
                throw ValidationException::withMessages([
                    'from' => 'That picture does not belong to this product.',
                ]);
            }

            $added[] = $this->copyToThumbnail($product, $source, $sort++);
        }

        if ($added === []) {
            throw ValidationException::withMessages([
                'images' => 'Send a picture to add, or name one the product already has.',
            ]);
        }

        foreach ($added as $thumbnail) {
            ScaleThumbnail::dispatch($thumbnail->id)->afterCommit();
        }

        return new ProductResource($product->fresh()->load('files'));
    }

    public function removeThumbnail(Request $request, $id, $fileId)
    {
        $product = Product::with('files')->findOrFail($id);

        if ((int) $product->user_id !== (int) Auth::user()->getAuthIdentifier()) {
            abort(403, 'You are not the owner of this product!');
        }

        $thumbnail = $product->files->first(
            fn (ProductFile $file) => (int) $file->id === (int) $fileId
                && $file->kind === ProductFile::KIND_THUMBNAIL
                && ! $file->isSuperseded()
        );

        if ($thumbnail === null) {
            abort(404);
        }

        // A listing with no picture is a blank card in the grid.
        if ($product->thumbnails()->count() <= 1) {
            throw ValidationException::withMessages([
                'thumbnails' => 'A listing needs a picture. Add another before removing this one.',
            ]);
        }

        Storage::disk($thumbnail->disk)->delete($thumbnail->path);
        $thumbnail->delete();

        return new ProductResource($product->fresh()->load('files'));
    }

    /**
     * Copied, never pointed at. The scaling job rewrites what a thumbnail holds,
     * and an attested render's bytes have to survive exactly as they were made.
     */
    private function copyToThumbnail(Product $product, ProductFile $source, int $sort): ProductFile
    {
        $bytes = (string) Storage::disk($source->disk)->get($source->path);
        $extension = pathinfo($source->path, PATHINFO_EXTENSION) ?: 'png';
        $path = 'thumbnails/'.uniqid().'.'.$extension;

        Storage::disk('public')->put($path, $bytes);

        return $product->files()->create([
            'kind' => ProductFile::KIND_THUMBNAIL,
            'disk' => 'public',
            'path' => $path,
            'sort' => $sort,
            'bytes' => strlen($bytes),
            'checksum' => hash('sha256', $bytes),
            'meta' => ['from' => $source->kind],
        ]);
    }

    private function storeFile(
        Product $product,
        UploadedFile $file,
        string $kind,
        string $disk,
        ?string $format = null,
        array $angles = [],
        int $sort = 0,
        ?ModelUpload $upload = null
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
        // under a name a web server may hand to an interpreter. A bundle keeps
        // its own extension because the archive is what the buyer downloads.
        $extension = match (true) {
            $upload === null => $file->extension(),
            $upload->isBundle() => 'zip',
            default => $upload->scan->format,
        };
        $name = uniqid().($extension === '' ? '' : '.'.$extension);
        $path = Storage::disk($disk)->putFileAs($directory, $file, $name);

        $stored = $product->files()->create([
            'kind' => $kind,
            'format' => $format,
            'disk' => $disk,
            'path' => $path,
            'sort' => $sort,
            'bytes' => $bytes,
            'checksum' => $checksum,
            'meta' => $upload === null ? null : array_filter([
                'sniffed_format' => $upload->scan->format,
                'faces' => $upload->scan->faces,
                'facts' => $upload->facts->toArray(),
                'angles' => $angles,
                'render' => ['status' => $angles === [] ? 'none' : 'queued', 'error' => null],
                // Only a bundle has these, and they say what the images were
                // drawn from when one file's checksum no longer can.
                // Named at upload rather than after a render, so a seller can
                // fix the paths before anyone browses the listing.
                'textures' => $upload->textureReport !== null
                    && ($upload->textureReport['missing'] !== [] || $upload->textureReport['unused'] !== [])
                    ? $upload->textureReport
                    : null,
                'bundle' => $upload->isBundle() ? [
                    'entry' => $upload->entry,
                    'digest' => $upload->digest,
                    'files' => $upload->manifest,
                ] : null,
            ], fn ($value) => $value !== null),
        ]);

        return $stored;
    }

    private function streamDeliverable(Product $product, string $format, bool $inline): StreamedResponse
    {
        $file = $product->deliverableFor($format);

        if ($file === null) {
            abort(404, 'That format is not available for this product.');
        }

        return $this->streamFile($file, $inline);
    }

    private function streamFile(ProductFile $file, bool $inline): StreamedResponse
    {
        return Storage::disk($file->disk)->response(
            $file->path,
            basename($file->path),
            [],
            $inline ? 'inline' : 'attachment'
        );
    }
}
