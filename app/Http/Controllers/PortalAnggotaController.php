<?php

namespace App\Http\Controllers;

use App\Http\Resources\AnggotaResource;
use App\Http\Resources\PinjamanResource;
use App\Http\Resources\TransactionResource;
use App\Models\Angsuran;
use App\Models\Pinjaman;
use App\Models\Simpanan;
use App\Models\TransaksiPos;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalAnggotaController extends Controller
{
    use ApiResponse;

    /**
     * Ringkasan portal anggota: simpanan, pinjaman, riwayat transaksi.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $anggota = $request->user()?->anggota;

        if (! $anggota) {
            return $this->errorResponse('Profil anggota tidak ditemukan.', null, 404);
        }

        if ($anggota->status !== 'Aktif') {
            return $this->errorResponse(
                'Akun belum aktif. Menunggu persetujuan admin.',
                new AnggotaResource($anggota->load('cabang')),
                403
            );
        }

        $anggota->load(['cabang', 'account']);

        $simpanan = Simpanan::where('id_anggota', $anggota->id_anggota)
            ->where('status', 'Verified')
            ->selectRaw('jenis_simpanan, SUM(jumlah) as total')
            ->groupBy('jenis_simpanan')
            ->get();

        $totalSimpanan = (float) Simpanan::where('id_anggota', $anggota->id_anggota)
            ->where('status', 'Verified')
            ->sum('jumlah');

        $pinjamans = Pinjaman::where('id_anggota', $anggota->id_anggota)
            ->orderByDesc('tanggal_pengajuan')
            ->get();

        $pinjamanAktif = $pinjamans->where('status', 'Approved')->map(function ($p) {
            $lastVerified = Angsuran::where('id_pinjaman', $p->id_pinjaman)
                ->where('status', 'Verified')
                ->orderByDesc('id_angsuran')
                ->first();

            $sisa = $lastVerified ? (float) $lastVerified->sisa_pinjaman : (float) $p->jumlah_pinjaman;

            return [
                'id_pinjaman' => $p->id_pinjaman,
                'jumlah_pinjaman' => (float) $p->jumlah_pinjaman,
                'tenor' => $p->tenor,
                'status' => $p->status,
                'sisa_pinjaman' => $sisa,
                'lunas' => $sisa <= 0,
            ];
        })->values();

        $transaksi = TransaksiPos::with(['kasir.cabang', 'detailTransaksi.produk'])
            ->where('id_anggota', $anggota->id_anggota)
            ->orderByDesc('tanggal_jam')
            ->take(20)
            ->get();

        return $this->successResponse('Data portal anggota berhasil diambil.', [
            'profil' => new AnggotaResource($anggota),
            'simpanan' => [
                'total' => $totalSimpanan,
                'rincian' => $simpanan,
            ],
            'pinjaman' => [
                'ringkasan' => PinjamanResource::collection($pinjamans),
                'aktif' => $pinjamanAktif,
            ],
            'riwayat_transaksi' => TransactionResource::collection($transaksi),
        ]);
    }

    public function riwayatTransaksi(Request $request): JsonResponse
    {
        $anggota = $request->user()?->anggota;
        if (! $anggota) {
            return $this->errorResponse('Profil anggota tidak ditemukan.', null, 404);
        }

        $transaksi = TransaksiPos::with(['kasir.cabang', 'detailTransaksi.produk'])
            ->where('id_anggota', $anggota->id_anggota)
            ->orderByDesc('tanggal_jam')
            ->get();

        return $this->successResponse(
            'Riwayat transaksi berhasil diambil.',
            TransactionResource::collection($transaksi)
        );
    }
}
