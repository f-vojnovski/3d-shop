<?php

namespace App\Http\Resources;

use App\Models\ProductFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewerId = $request->user()?->getAuthIdentifier();
        $status = $this->statusFor($viewerId);
        $formats = $this->availableFormats();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            'user_id' => $this->user_id,
            'preview_mode' => $this->preview_mode,
            'previews' => $this->previewsByFormat(),
            'seller_images' => $this->sellerImageList(),
            'preview_status' => $this->preview_status,
            'preview_error' => $this->preview_error,
            'unlisted' => $this->unlisted,
            'created_at' => $this->created_at,
            'thumbnail_url' => $this->thumbnailUrl(),
            'formats' => $formats,
            'preview_urls' => $this->previewUrls($formats),
            'download_urls' => $this->isDownloadableBy($viewerId)
                ? $this->downloadUrls($formats, $viewerId)
                : null,
            'product_status' => $status,
        ];
    }

    // Server-rendered stills, each carrying the camera it was rendered from.
    /**
     * One entry per model file: its angles, its own render status and its
     * stills. The seller's tabs and the buyer's format switch both read this.
     */
    private function previewsByFormat(): array
    {
        return $this->files
            ->where('kind', ProductFile::KIND_DELIVERABLE)
            ->sortBy('format')
            ->map(fn (ProductFile $file) => [
                'format' => $file->format,
                'angles' => $file->angles(),
                'status' => $file->renderStatus(),
                'error' => $file->renderError(),
                'images' => $this->stillsFrom($file),
            ])
            ->values()
            ->all();
    }

    /** Separate from `previews`: nothing here is attested. */
    private function sellerImageList(): array
    {
        return $this->files
            ->where('kind', ProductFile::KIND_SELLER_IMAGE)
            ->sortBy('sort')
            ->map(fn (ProductFile $file) => [
                'id' => $file->id,
                'url' => Storage::disk($file->disk)->url($file->path),
                'sort' => $file->sort,
            ])
            ->values()
            ->all();
    }

    private function stillsFrom(ProductFile $source): array
    {
        return $this->files
            ->where('kind', ProductFile::KIND_PREVIEW_IMAGE)
            ->where('source_file_id', $source->id)
            ->sortBy('sort')
            ->map(fn (ProductFile $file) => $this->describeStill($file))
            ->values()
            ->all();
    }

    private function describeStill(ProductFile $file): array
    {
        return [
            'id' => $file->id,
            'url' => Storage::disk($file->disk)->url($file->path),
            'sort' => $file->sort,
            'source_format' => $file->meta['source_format'] ?? null,
            'camera' => $file->meta['camera'] ?? null,
            'checksum' => $file->checksum,
            'source_checksum' => $file->meta['source_checksum'] ?? null,
            'attestation_url' => "/api/previews/{$file->id}/attestation",
        ];
    }

    private function thumbnailUrl(): ?string
    {
        $thumbnail = $this->thumbnail();

        return $thumbnail
            ? Storage::disk($thumbnail->disk)->url($thumbnail->path)
            : null;
    }

    // Preview is public, but only while the seller chooses to expose geometry.
    private function previewUrls(array $formats): array
    {
        if (! $this->servesInteractivePreview()) {
            return [];
        }

        $urls = [];

        foreach ($formats as $format) {
            $urls[$format] = "/api/products/{$this->id}/preview/{$format}";
        }

        return $urls;
    }

    // A short-lived capability, like a presigned URL: it cannot be forged or
    // altered, it expires, and it is only ever issued to someone entitled.
    // Anyone holding the link can use it until it expires.
    private function downloadUrls(array $formats, ?int $viewerId): array
    {
        $urls = [];

        foreach ($formats as $format) {
            $urls[$format] = URL::temporarySignedRoute(
                'products.download',
                now()->addMinutes(15),
                ['id' => $this->id, 'format' => $format, 'user' => $viewerId],
                absolute: false
            );
        }

        return $urls;
    }
}
