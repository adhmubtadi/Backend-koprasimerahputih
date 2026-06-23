<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\DetailJurnal;
use App\Models\Pinjaman;
use App\Models\Produk;
use App\Models\Simpanan;
use App\Models\UsulanStok;
use App\Traits\ApiResponse;
use App\Traits\ResolvesCabangScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            $produkQuery = Produk::query();
            $pinjamanQuery = Pinjaman::query();
            $usulanQuery = UsulanStok::query();
            $angsuranQuery = Angsuran::query();

            if ($cabangScope !== null) {
                $anggotaQuery->where('id_cabang', $cabangScope);
                $simpananQuery->whereHas('anggota', fn ($q) => $q->where('id_cabang', $cabangScope));
                $produkQuery->where(function ($q) use ($cabangScope) {
                    $q->where('id_cabang', $cabangScope)->orWhereNull('id_cabang');
                });
                $pinjamanQuery->whereHas('anggota', fn ($q) => $q->where('id_cabang', $cabangScope));
                $usulanQuery->where('id_cabang', $cabangScope);
                $angsuranQuery->whereHas('pinjaman.anggota', fn ($q) => $q->where('id_cabang', $cabangScope));
            }

            $kasQuery = DetailJurnal::whereHas('akun', fn ($q) => $q->where('nama_akun', 'like', '%Kas%'));
            if ($cabangScope !== null) {
                $kasQuery->whereHas('jurnal', fn ($q) => $q->where('id_cabang', $cabangScope));
            }
            $saldoKas = (float) ($kasQuery->selectRaw('SUM(debit) - SUM(kredit) as total')->value('total') ?? 0);

            $lastVerifiedSub = Angsuran::selectRaw('id_pinjaman, MAX(id_angsuran) as last_id')
                ->where('status', 'Verified')
                ->groupBy('id_pinjaman');

            $piutangQuery = Pinjaman::query()
                ->where('pinjamans.status', 'Approved')
                ->leftJoinSub($lastVerifiedSub, 'lv', function ($join) {
                    $join->on('pinjamans.id_pinjaman', '=', 'lv.id_pinjaman');
                })
                ->leftJoin('angsurans as a', 'a.id_angsuran', '=', 'lv.last_id');

            if ($cabangScope !== null) {
                $piutangQuery->whereHas('anggota', fn ($q) => $q->where('id_cabang', $cabangScope));
            }

            $piutangBerjalan = (float) ($piutangQuery
                ->selectRaw('SUM(COALESCE(a.sisa_pinjaman, pinjamans.jumlah_pinjaman)) as total')
                ->value('total') ?? 0);

            $stats = [
                'id_cabang' => $cabangScope,
                'total_anggota' => $anggotaQuery->count(),
                'kas_koperasi' => $saldoKas,
                'total_simpanan' => (float) $simpananQuery->sum('jumlah'),
                'total_pinjaman' => (float) (clone $pinjamanQuery)->where('status', 'Approved')->sum('jumlah_pinjaman'),
                'piutang_berjalan' => $piutangBerjalan,
                'aset_produk' => (float) $produkQuery->selectRaw('SUM(harga_beli * stok) as total')->value('total') ?? 0,
            ];

            $performance = [
                'angsuran_masuk_bulan_ini' => (float) (clone $angsuranQuery)
                    ->whereMonth('tanggal_bayar', now()->month)
                    ->whereYear('tanggal_bayar', now()->year)
                    ->where('status', 'Verified')
                    ->sum('jumlah_bayar'),
                'pinjaman_keluar_bulan_ini' => (float) (clone $pinjamanQuery)
                    ->whereMonth('tanggal_pengajuan', now()->month)
                    ->whereYear('tanggal_pengajuan', now()->year)
                    ->where('status', 'Approved')
                    ->sum('jumlah_pinjaman'),
            ];

            $alerts = [
                'stok_kritis' => (clone $produkQuery)
                    ->where('stok', '<', $threshold)
                    ->orderBy('stok', 'asc')
                    ->take(10)
                    ->get(['nama_produk', 'stok']),
                'total_produk' => (clone $produkQuery)->count(),
                'threshold' => $threshold,
            ];

            $pendingTasks = [
                'pinjaman_pending' => (clone $pinjamanQuery)->where('status', 'Pending')->count(),
                'usulan_stok_pending' => (clone $usulanQuery)->where('status', 'Pending')->count(),
                'angsuran_unverified' => (clone $angsuranQuery)->where('status', 'Pending')->count(),
                'calon_anggota' => (clone $anggotaQuery)->where('status', 'Calon')->count(),
            ];

            $chartSimpanan = (clone $simpananQuery)
                ->selectRaw("DATE_FORMAT(tanggal, '%M') as bulan, SUM(jumlah) as total")
                ->groupBy('bulan')
                ->orderBy(DB::raw('MIN(tanggal)'), 'asc')
                ->take(6)
                ->get();

            $recentActivities = (clone $pinjamanQuery)
                ->with('anggota')
                ->orderByDesc('tanggal_pengajuan')
                ->take(5)
                ->get();

            return $this->successResponse('Dashboard data synced successfully.', [
                'statistics' => $stats,
                'performance' => $performance,
                'inventory' => $alerts,
                'pending_actions' => $pendingTasks,
                'charts' => $chartSimpanan,
                'recent_loans' => $recentActivities,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal memuat dashboard.', $e->getMessage(), 500);
        }
    }
}
