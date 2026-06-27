<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AngsuranResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id_angsuran' => $this->id_angsuran,
            'id_pinjaman' => $this->id_pinjaman,
            'jumlah_bayar' => (float) $this->jumlah_bayar,
            'pokok_bayar' => (float) ($this->pokok_bayar ?? 0),
            'fee_bayar' => (float) ($this->fee_bayar ?? 0),
            'tanggal_bayar' => optional($this->tanggal_bayar)->format('Y-m-d'),
            'sisa_pinjaman' => (float) $this->sisa_pinjaman,
            'status' => $this->status,
            'url_bukti' => $this->bukti_transfer ? asset('storage/' . $this->bukti_transfer) : null,
        ];
    }
}
