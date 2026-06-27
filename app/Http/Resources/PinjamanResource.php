<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PinjamanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();

        return [
            'id_pinjaman' => $this->id_pinjaman,
            'id_anggota' => $this->id_anggota,
            'id_pengurus_acc' => $this->id_pengurus_acc,
            'jumlah_pinjaman' => (float) $this->jumlah_pinjaman,
            'jumlah_pinjaman_rupiah' => 'Rp '.number_format((float) $this->jumlah_pinjaman, 0, ',', '.'),
            'biaya_operasional' => (float) $this->biaya_operasional,
            'biaya_operasional_rupiah' => 'Rp '.number_format((float) $this->biaya_operasional, 0, ',', '.'),
            'tenor' => (int) $this->tenor,
            'tanggal_pengajuan' => optional($this->tanggal_pengajuan)->format('Y-m-d'),
            'status' => $this->status,
            'sisa_pinjaman' => $this->when(array_key_exists('sisa_pinjaman', $attributes), fn () => (float) ($this->sisa_pinjaman ?? $this->jumlah_pinjaman)),
            'sisa_tenor' => $this->when(array_key_exists('verified_installments_count', $attributes), fn () => max(0, (int) $this->tenor - (int) $this->verified_installments_count)),
            'pending_installments_count' => $this->when(array_key_exists('pending_installments_count', $attributes), fn () => (int) $this->pending_installments_count),
            'anggota' => $this->whenLoaded('anggota', fn () => [
                'id_anggota' => $this->anggota?->id_anggota,
                'nomor_anggota' => $this->anggota?->nomor_anggota,
                'nama_anggota' => $this->anggota?->nama_anggota,
                'status' => $this->anggota?->status,
                'id_cabang' => $this->anggota?->id_cabang,
                'cabang' => $this->anggota?->relationLoaded('cabang') && $this->anggota?->cabang ? [
                    'id_cabang' => $this->anggota->cabang->id_cabang,
                    'nama_cabang' => $this->anggota->cabang->nama_cabang,
                    'lokasi' => $this->anggota->cabang->lokasi,
                ] : null,
            ]),
        ];
    }
}
