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

        $anggota->load([
            'cabang:id_cabang,nama_cabang,lokasi',
            'account:id_account,username,role',
        ]);

        $simpanan = Simpanan::where('id_anggota', $anggota->id_anggota)
            ->where('status', 'Verified')
            ->selectRaw('jenis_simpanan, SUM(jumlah) as total')
            ->groupBy('jenis_simpanan')
            ->get();

        $totalSimpanan = (float) $simpanan->sum('total');

        $pinjamans = Pinjaman::where('id_anggota', $anggota->id_anggota)
            ->select([
                'id_pinjaman',
                'id_anggota',
                'id_pengurus_acc',
                'jumlah_pinjaman',
                'biaya_operasional',
                'tenor',
                'tanggal_pengajuan',
                'status',
            ])
            ->orderByDesc('tanggal_pengajuan')
            ->get();

        $latestVerifiedAngsurans = Angsuran::whereIn(
            'id_pinjaman',
            $pinjamans->where('status', 'Approved')->pluck('id_pinjaman')
        )
            ->where('status', 'Verified')
            ->select(['id_angsuran', 'id_pinjaman', 'sisa_pinjaman'])
            ->orderByDesc('id_angsuran')
            ->get()
            ->unique('id_pinjaman')
            ->keyBy('id_pinjaman');

        $pinjamanAktif = $pinjamans->where('status', 'Approved')->map(function ($p) use ($latestVerifiedAngsurans) {
            $lastVerified = $latestVerifiedAngsurans->get($p->id_pinjaman);
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

        $transaksi = TransaksiPos::with([
            'detailTransaksi:id_detail,id_transaksi,id_produk,jumlah,harga_satuan',
            'detailTransaksi.produk:id_produk,nama_produk',
        ])
            ->where('id_anggota', $anggota->id_anggota)
            ->select(['id_transaksi', 'id_kasir', 'id_anggota', 'tanggal_jam', 'total_bayar', 'ppn'])
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
