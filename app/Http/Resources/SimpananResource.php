<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SimpananResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id_simpanan' => $this->id_simpanan,
            'id_anggota' => $this->id_anggota,
            'jenis_simpanan' => $this->jenis_simpanan,
            'jumlah' => (float) $this->jumlah,
            'tanggal' => optional($this->tanggal)->format('Y-m-d'),
            'status' => $this->status,
            'url_bukti' => $this->bukti_transfer ? asset('storage/' . $this->bukti_transfer) : null,
            'anggota' => $this->whenLoaded('anggota', fn () => [
                'id_anggota' => $this->anggota?->id_anggota,
                'nama_anggota' => $this->anggota?->nama_anggota,
                'status' => $this->anggota?->status,
                'id_cabang' => $this->anggota?->id_cabang,
            ]),
        ];
    }
}
