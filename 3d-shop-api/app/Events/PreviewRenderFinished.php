<?php

namespace App\Events;

use App\Models\Product;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PreviewRenderFinished implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $sellerId,
        public int $productId,
        public string $productName,
        public string $format,
        public string $status,
        public ?string $error,
        public int $images,
    ) {}

    public static function for(Product $product, string $format): self
    {
        $source = $product->deliverables()->where('format', $format)->first();

        return new self(
            sellerId: (int) $product->user_id,
            productId: (int) $product->id,
            productName: (string) $product->name,
            format: $format,
            status: $source?->renderStatus() ?? 'none',
            error: $source?->renderError(),
            images: $source?->stills()->count() ?? 0,
        );
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('sellers.'.$this->sellerId)];
    }

    public function broadcastAs(): string
    {
        return 'preview.render.finished';
    }
}
