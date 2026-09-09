<?php

namespace App\Http\Resources;

use App\Models\Product;
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
            'preview_angles' => $this->preview_angles ?? [],
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
