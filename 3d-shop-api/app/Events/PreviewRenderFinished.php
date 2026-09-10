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
        public string $status,
        public ?string $error,
        public int $images,
    ) {}

    public static function for(Product $product): self
    {
        return new self(
            sellerId: (int) $product->user_id,
            productId: (int) $product->id,
            productName: (string) $product->name,
            status: (string) $product->preview_status,
            error: $product->preview_error,
            images: $product->previewImages()->count(),
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
