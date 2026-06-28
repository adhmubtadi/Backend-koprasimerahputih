<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InventoryUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $action,
        public ?int $idCabang = null,
        public ?string $kodeUsulan = null,
        public array $payload = [],
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('inventory');
    }

    public function broadcastAs(): string
    {
        return 'inventory.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'id_cabang' => $this->idCabang,
            'kode_usulan' => $this->kodeUsulan,
            'payload' => $this->payload,
            'sent_at' => now()->toISOString(),
        ];
    }
}
