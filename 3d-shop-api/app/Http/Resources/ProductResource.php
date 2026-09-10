<?php

namespace App\Http\Resources;

use App\Models\ProductFile;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        $entitled = $this->isDownloadableBy($viewerId);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            'user_id' => $this->user_id,
            'preview_mode' => $this->preview_mode,
            'previews' => $this->previewsByFormat($entitled ? $viewerId : null),
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

    /**
     * One entry per model file: its angles, its own render status and its
     * stills. The seller's tabs and the buyer's format switch both read this.
     */
    private function previewsByFormat(?int $entitledViewerId): array
    {
        return $this->files
            ->where('kind', ProductFile::KIND_DELIVERABLE)
            ->reject(fn (ProductFile $file) => $file->isSuperseded())
            ->sortBy('format')
            ->map(fn (ProductFile $file) => [
                'format' => $file->format,
                'angles' => $file->angles(),
                'status' => $file->renderStatus(),
                'error' => $file->renderError(),
                'facts' => $file->facts(),
                'bytes' => $file->bytes,
                'images' => $this->stillsFrom($file),
                'replaced' => $this->replacementsOf($file->format, $entitledViewerId),
            ])
            ->values()
            ->all();
    }

    /** What this format used to be. Public, not owner-only. */
    private function replacementsOf(?string $format, ?int $entitledViewerId): array
    {
        return $this->files
            ->where('kind', ProductFile::KIND_DELIVERABLE)
            ->where('format', $format)
            ->filter(fn (ProductFile $file) => $file->isSuperseded())
            // Id breaks ties: two replacements can land in the same second.
            ->sortByDesc(fn (ProductFile $file) => [$file->superseded_at, $file->id])
            ->map(fn (ProductFile $file) => [
                'replaced_at' => $file->superseded_at,
                'note' => $file->replacement_note,
                'sha256' => $file->checksum,
                'facts' => $file->facts(),
                'download_url' => $entitledViewerId === null
                    ? null
                    : $this->versionUrl($file, $entitledViewerId),
                'images' => $this->files
                    ->where('kind', ProductFile::KIND_PREVIEW_IMAGE)
                    ->where('source_file_id', $file->id)
                    ->sortBy('sort')
                    ->map(fn (ProductFile $still) => [
                        'id' => $still->id,
                        'url' => Storage::disk($still->disk)->url($still->path),
                        'sort' => $still->sort,
                        'attestation_url' => "/api/previews/{$still->id}/attestation",
                    ])
                    ->values()
                    ->all(),
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
        $outlines = $this->currentFiles(ProductFile::KIND_WIREFRAME, $source)->keyBy('sort');

        return $this->currentFiles(ProductFile::KIND_PREVIEW_IMAGE, $source)
            ->sortBy('sort')
            ->map(fn (ProductFile $file) => $this->describeStill($file, $outlines->get($file->sort)))
            ->values()
            ->all();
    }

    private function currentFiles(string $kind, ProductFile $source): Collection
    {
        return $this->files
            ->where('kind', $kind)
            ->where('source_file_id', $source->id)
            ->reject(fn (ProductFile $file) => $file->isSuperseded());
    }

    private function describeStill(ProductFile $file, ?ProductFile $outline): array
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
            'wireframe' => $outline === null ? null : [
                'id' => $outline->id,
                'url' => Storage::disk($outline->disk)->url($outline->path),
                'checksum' => $outline->checksum,
                'attestation_url' => "/api/previews/{$outline->id}/attestation",
            ],
        ];
    }

    private function versionUrl(ProductFile $file, int $viewerId): string
    {
        return URL::temporarySignedRoute(
            'products.download-version',
            now()->addMinutes(15),
            ['id' => $this->id, 'file' => $file->getKey(), 'user' => $viewerId],
            absolute: false
        );
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
