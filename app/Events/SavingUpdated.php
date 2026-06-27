<?php

namespace App\Events;

use App\Models\Simpanan;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SavingUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $action,
        public Simpanan $simpanan
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('savings');
    }

    public function broadcastAs(): string
    {
        return 'saving.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'action'      => $this->action,
            'id_simpanan' => $this->simpanan->id_simpanan,
            'id_anggota'  => $this->simpanan->id_anggota,
            'status'      => $this->simpanan->status,
            'sent_at'     => now()->toISOString(),
        ];
    }
}
