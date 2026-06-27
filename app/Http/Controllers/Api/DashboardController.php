<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\BranchProductStock;
use App\Models\Pinjaman;
use App\Models\Produk;
use App\Models\Simpanan;
use App\Models\UsulanStok;
use App\Traits\ApiResponse;
use App\Traits\ResolvesCabangScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponse, ResolvesCabangScope;

    public function index(Request $request): JsonResponse
    {
        try {
            $cabangScope = $this->resolveCabangScope($request);
            $threshold = (int) config('koperasi.stok_warning_threshold', 100);

            $anggotaQuery = Anggota::query();
            $simpananQuery = Simpanan::query()->where('status', 'Verified');
            $stockQuery = BranchProductStock::query()->with(['cabang:id_cabang,nama_cabang', 'produk:id_produk,id_supplier,nama_produk', 'produk.supplier:id_supplier,nama_supplier']);
            $pinjamanQuery = Pinjaman::query();
            $usulanQuery = UsulanStok::query();
            $angsuranQuery = Angsuran::query();

            if ($cabangScope !== null) {
                $anggotaQuery->where('id_cabang', $cabangScope);
                $simpananQuery->whereHas('anggota', fn ($q) => $q->where('id_cabang', $cabangScope));
                $stockQuery->where('id_cabang', $cabangScope);
                $pinjamanQuery->whereHas('anggota', fn ($q) => $q->where('id_cabang', $cabangScope));
                $usulanQuery->where('id_cabang', $cabangScope);
                $angsuranQuery->whereHas('pinjaman.anggota', fn ($q) => $q->where('id_cabang', $cabangScope));
            }

            $stats = [
                'id_cabang' => $cabangScope,
                'total_anggota' => $anggotaQuery->count(),
                'anggota_aktif' => (clone $anggotaQuery)->where('status', 'Aktif')->count(),
                'total_simpanan' => (float) $simpananQuery->sum('jumlah'),
            ];

            $alerts = [
                'stok_kritis' => (clone $stockQuery)
                    ->where('stok', '<', $threshold)
                    ->orderBy('stok', 'asc')
                    ->take(10)
                    ->get()
                    ->map(fn (BranchProductStock $stock) => [
                        'id_produk' => $stock->id_produk,
                        'id_cabang' => $stock->id_cabang,
                        'nama_cabang' => $stock->cabang?->nama_cabang,
                        'nama_produk' => $stock->produk?->nama_produk,
                        'stok' => (int) $stock->stok,
                        'nama_supplier' => $stock->produk?->supplier?->nama_supplier,
                    ]),
                // Daftar dibatasi agar payload ringan, tetapi total harus memakai
                // seluruh produk kritis supaya sama dengan modul inventaris.
                'total_stok_kritis' => (clone $stockQuery)->where('stok', '<', $threshold)->count(),
                'total_produk' => (clone $stockQuery)->distinct('id_produk')->count('id_produk'),
                'total_stok' => (int) (clone $stockQuery)->sum('stok'),
                'threshold' => $threshold,
            ];

            $pendingTasks = [
                'pinjaman_pending' => (clone $pinjamanQuery)->where('status', 'Pending')->count(),
                'usulan_stok_pending' => (clone $usulanQuery)->where('status', 'Pending')->count(),
                'angsuran_unverified' => (clone $angsuranQuery)->where('status', 'Pending')->count(),
                'calon_anggota' => (clone $anggotaQuery)->where('status', 'Calon')->count(),
            ];

            $recentActivities = collect()
                ->merge((clone $pinjamanQuery)->with('anggota')->orderByDesc('tanggal_pengajuan')->take(5)->get()->map(fn (Pinjaman $item) => [
                    'id' => 'pinjaman-'.$item->id_pinjaman,
                    'type' => 'keuangan',
                    'user' => $item->anggota?->nama_anggota ?? 'Anggota',
                    'role' => 'Anggota',
                    'action' => 'Mengajukan pinjaman',
                    'amount' => (float) $item->jumlah_pinjaman,
                    'date' => $item->tanggal_pengajuan?->toDateString(),
                ]))
                ->merge((clone $simpananQuery)->with('anggota')->orderByDesc('tanggal')->take(5)->get()->map(fn (Simpanan $item) => [
                    'id' => 'simpanan-'.$item->id_simpanan,
                    'type' => 'keuangan',
                    'user' => $item->anggota?->nama_anggota ?? 'Anggota',
                    'role' => 'Anggota',
                    'action' => 'Setoran simpanan '.$item->jenis_simpanan,
                    'amount' => (float) $item->jumlah,
                    'date' => $item->tanggal?->toDateString(),
                ]))
                ->merge((clone $angsuranQuery)->with('pinjaman.anggota')->where('status', 'Verified')->orderByDesc('tanggal_bayar')->take(5)->get()->map(fn (Angsuran $item) => [
                    'id' => 'angsuran-'.$item->id_angsuran,
                    'type' => 'keuangan',
                    'user' => $item->pinjaman?->anggota?->nama_anggota ?? 'Anggota',
                    'role' => 'Anggota',
                    'action' => 'Membayar angsuran pinjaman',
                    'amount' => (float) $item->jumlah_bayar,
                    'date' => $item->tanggal_bayar?->toDateString(),
                ]))
                ->merge((clone $usulanQuery)->with(['cabang', 'gudang', 'produk'])->orderByDesc('tanggal_usulan')->take(5)->get()->map(fn (UsulanStok $item) => [
                    'id' => 'usulan-'.$item->id_usulan,
                    'type' => 'inventaris',
                    'user' => $item->gudang?->nama_petugas ?? 'Petugas Gudang',
                    'role' => 'Gudang',
                    'action' => 'Mengajukan stok '.$item->produk?->nama_produk.' untuk '.$item->cabang?->nama_cabang,
                    'amount' => (float) $item->jumlah * (float) $item->harga_beli,
                    'date' => $item->tanggal_usulan?->toDateString(),
                ]))
                ->filter(fn (array $item) => filled($item['date']))
                ->sortByDesc('date')
                ->take(5)
                ->values();

            return $this->successResponse('Dashboard data synced successfully.', [
                'statistics' => $stats,
                'inventory' => $alerts,
                'pending_actions' => $pendingTasks,
                'recent_activities' => $recentActivities,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal memuat dashboard.', $e->getMessage(), 500);
        }
    }

}
