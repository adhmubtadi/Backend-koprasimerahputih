<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnggotaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id_anggota' => $this->id_anggota,
            'id_account' => $this->id_account,
            'nomor_anggota' => $this->nomor_anggota,
            'nama_anggota' => $this->nama_anggota,
            'alamat' => $this->alamat,
            'no_hp' => $this->no_hp,
            'email' => $this->email,
            'tanggal_daftar' => $this->tanggal_daftar?->format('Y-m-d'),
            'status' => $this->status,
            'role' => $this->account?->role ?? 'Anggota',
            'peran' => $this->account?->role ?? 'Anggota',
            'id_cabang' => $this->id_cabang,
            'cabang' => $this->whenLoaded('cabang', fn () => [
                'id_cabang' => $this->cabang->id_cabang,
                'nama_cabang' => $this->cabang->nama_cabang,
                'lokasi' => $this->cabang->lokasi,
            ]),
            'account' => $this->whenLoaded('account', fn () => [
                'id_account' => $this->account->id_account,
                'username' => $this->account->username,
                'role' => $this->account->role,
            ]),
            'simpanans' => $this->whenLoaded('simpanans'),
            'pinjamans' => $this->whenLoaded('pinjamans'),
            'transaksi_pos' => $this->whenLoaded('transaksiPos'),
        ];
    }
}
