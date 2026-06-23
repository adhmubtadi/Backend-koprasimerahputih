<?php

namespace App\Services;

use App\Models\Anggota;
use App\Models\Pinjaman;
use RuntimeException;

class PinjamanService
{
    public function ajukanPinjaman(array $data): Pinjaman
    {
        $anggota = Anggota::find($data['id_anggota'] ?? null);
        if (! $anggota) {
            throw new RuntimeException('Anggota tidak ditemukan.');
        }

        if ($anggota->status !== 'Aktif') {
            throw new RuntimeException('Hanya anggota aktif yang dapat mengajukan pinjaman.');
        }

        $tenor = (string) ($data['tenor'] ?? '');
        if (! in_array($tenor, ['6', '12', '18', '24'], true)) {
            throw new \RuntimeException('Tenor tidak valid. Hanya menerima tenor 6, 12, 18, atau 24 bulan.');
        }

        $jumlahPinjaman = (float) $data['jumlah_pinjaman'];
        $feePercent = 0.02;

        return Pinjaman::create([
            'id_anggota' => $anggota->id_anggota,
            'id_pengurus_acc' => null,
            'jumlah_pinjaman' => $jumlahPinjaman,
            'biaya_operasional' => round($jumlahPinjaman * $feePercent, 2),
            'tenor' => $tenor,
            'tanggal_pengajuan' => $data['tanggal_pengajuan'] ?? now()->toDateString(),
            'status' => 'Pending',
        ]);
    }
}
