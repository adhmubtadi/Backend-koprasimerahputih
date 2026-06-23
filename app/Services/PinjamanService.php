<?php

namespace App\Services;

use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\Pinjaman;
use App\Models\Simpanan;
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
        $feePercent = (float) config('koperasi.biaya_operasional_persen', 0.02);

        $pendingExists = Pinjaman::where('id_anggota', $anggota->id_anggota)
            ->where('status', 'Pending')
            ->exists();

        if ($pendingExists) {
            throw new RuntimeException('Anda masih memiliki pengajuan pinjaman yang menunggu verifikasi.');
        }

        $totalSimpanan = (float) Simpanan::where('id_anggota', $anggota->id_anggota)->sum('jumlah');
        $activeLoans = Pinjaman::where('id_anggota', $anggota->id_anggota)
            ->where('status', 'Approved')
            ->get();

        $hasActiveLoan = false;
        $totalSisaPinjaman = $activeLoans->sum(function (Pinjaman $pinjaman) use (&$hasActiveLoan) {
            $lastVerified = Angsuran::where('id_pinjaman', $pinjaman->id_pinjaman)
                ->where('status', 'Verified')
                ->orderByDesc('id_angsuran')
                ->first();

            $sisa = (float) ($lastVerified ? $lastVerified->sisa_pinjaman : $pinjaman->jumlah_pinjaman);
            if ($sisa > 0) {
                $hasActiveLoan = true;
            }
            return $sisa;
        });

        if ($hasActiveLoan) {
            throw new RuntimeException('Anda masih memiliki pinjaman berjalan yang belum lunas. Silakan lunasi pinjaman sebelumnya terlebih dahulu.');
        }

        $limitMultiplier = (float) config('koperasi.pinjaman_limit_multiplier_simpanan', 3);
        $limitPinjaman = max(0.0, ($totalSimpanan * $limitMultiplier) - $totalSisaPinjaman);

        if ($jumlahPinjaman > $limitPinjaman) {
            throw new RuntimeException(
                'Nominal pinjaman melebihi limit tersedia. Limit Anda saat ini Rp ' .
                number_format($limitPinjaman, 0, ',', '.')
            );
        }

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
