<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SaleCreated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public array $sale) {}

    public function broadcastOn(): Channel
    {
        return new Channel('sales');
    }

    public function broadcastAs(): string
    {
        return 'sale.created';
    }

    public function broadcastWith(): array
    {
        return [
            'sale' => $this->sale,
            'sent_at' => now()->toISOString(),
        ];
    }
}
