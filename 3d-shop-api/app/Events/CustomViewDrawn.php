<?php

namespace App\Events;

use App\Models\CustomView;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CustomViewDrawn implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $viewerId,
        public int $viewId,
        public int $productId,
        public string $pass,
        public string $status,
        public ?string $url,
        public ?string $error,
    ) {}

    public static function for(CustomView $view): self
    {
        return new self(
            viewerId: (int) $view->user_id,
            viewId: (int) $view->id,
            productId: (int) $view->product_id,
            pass: (string) $view->pass,
            status: (string) $view->status,
            url: $view->url(),
            error: $view->failure_reason,
        );
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('viewers.'.$this->viewerId)];
    }

    public function broadcastAs(): string
    {
        return 'custom.view.drawn';
    }
}
