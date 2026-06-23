<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Anggota;
use App\Models\Akun;
use App\Models\Angsuran;
use App\Models\DetailJurnal;
use App\Models\Jurnal;
use App\Models\Pinjaman;
use App\Models\Produk;
use App\Models\Simpanan;
use App\Models\TransaksiPos;
use App\Traits\ApiResponse;
use App\Traits\ResolvesCabangScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use ApiResponse, ResolvesCabangScope;

    /**
     * Laporan penjualan dengan filter kasir dan bulan/tahun.
     * Query params:
     * - id_kasir (optional)
     * - month (1-12, optional, default bulan ini)
     * - year (optional, default tahun ini)
     */
    public function sales(Request $request): JsonResponse
    {
        try {
            $period = $request->query('period', 'monthly');
            $month = (int) ($request->query('month') ?? now()->month);
            $year = (int) ($request->query('year') ?? now()->year);
            $date = $request->query('date');
            $idKasir = $request->query('id_kasir');
            $cabangScope = $this->resolveCabangScope($request);

            $query = TransaksiPos::query()->with(['kasir.cabang', 'detailTransaksi.produk']);

            if ($period === 'daily' && $date) {
                $query->whereDate('tanggal_jam', $date);
            } elseif ($period === 'yearly') {
                $query->whereYear('tanggal_jam', $year);
            } else {
                $query->whereMonth('tanggal_jam', $month)->whereYear('tanggal_jam', $year);
            }

            if ($idKasir !== null) {
                $query->where('id_kasir', (int) $idKasir);
            }

            if ($cabangScope !== null) {
                $query->whereHas('kasir', fn ($q) => $q->where('id_cabang', $cabangScope));
            }

            $data = $query->orderByDesc('tanggal_jam')->get();

            $totalPenjualan = (float) $data->sum('total_bayar');
            $totalHpp = 0.0;
            foreach ($data as $trx) {
                foreach ($trx->detailTransaksi as $detail) {
                    $hargaBeli = (float) ($detail->produk?->harga_beli ?? 0);
                    $totalHpp += $hargaBeli * (int) $detail->jumlah;
                }
            }

            return $this->successResponse('Laporan penjualan berhasil diambil.', [
                'filters' => [
                    'period' => $period,
                    'date' => $date,
                    'month' => $month,
                    'year' => $year,
                    'id_kasir' => $idKasir ? (int) $idKasir : null,
                    'id_cabang' => $cabangScope,
                ],
                'summary' => [
                    'total_transaksi' => $data->count(),
                    'total_penjualan' => $totalPenjualan,
                    'total_hpp' => $totalHpp,
                    'keuntungan' => $totalPenjualan - $totalHpp,
                ],
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal memuat laporan penjualan.', $e->getMessage(), 500);
        }
    }

    /**
     * Laporan pinjaman: status pembayaran, tunggakan, akumulasi biaya operasional.
     */
    public function pinjaman(Request $request): JsonResponse
    {
        try {
            $status = $request->query('status');
            $cabangScope = $this->resolveCabangScope($request);

            $query = Pinjaman::with(['anggota.cabang', 'pengurusAcc', 'angsurans']);
            if ($status) {
                $query->where('status', $status);
            }
            if ($cabangScope !== null) {
                $query->whereHas('anggota', fn ($q) => $q->where('id_cabang', $cabangScope));
            }

            $pinjamans = $query->orderByDesc('tanggal_pengajuan')->get();

            $rows = $pinjamans->map(function ($p) {
                $lastVerified = Angsuran::where('id_pinjaman', $p->id_pinjaman)
                    ->where('status', 'Verified')
                    ->orderByDesc('id_angsuran')
                    ->first();

                $sisa = $lastVerified ? (float) $lastVerified->sisa_pinjaman : (float) $p->jumlah_pinjaman;
                $feeTerkumpul = (float) Angsuran::where('id_pinjaman', $p->id_pinjaman)
                    ->where('status', 'Verified')
                    ->sum('fee_bayar');

                return [
                    'id_pinjaman' => $p->id_pinjaman,
                    'anggota' => $p->anggota?->only(['id_anggota', 'nomor_anggota', 'nama_anggota', 'id_cabang']),
                    'jumlah_pinjaman' => (float) $p->jumlah_pinjaman,
                    'biaya_operasional' => (float) $p->biaya_operasional,
                    'fee_terkumpul' => $feeTerkumpul,
                    'tenor' => $p->tenor,
                    'status' => $p->status,
                    'sisa_pinjaman' => $sisa,
                    'tunggakan' => $p->status === 'Approved' && $sisa > 0,
                    'lunas' => $p->status === 'Approved' && $sisa <= 0,
                ];
            })->values();

            return $this->successResponse('Laporan pinjaman berhasil diambil.', [
                'filters' => [
                    'status' => $status,
                    'id_cabang' => $cabangScope,
                ],
                'summary' => [
                    'total_pinjaman' => $rows->count(),
                    'total_pokok' => (float) $rows->sum('jumlah_pinjaman'),
                    'total_biaya_operasional' => (float) $rows->sum('biaya_operasional'),
                    'total_fee_terkumpul' => (float) $rows->sum('fee_terkumpul'),
                    'jumlah_tunggakan' => $rows->where('tunggakan', true)->count(),
                ],
                'data' => $rows,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal memuat laporan pinjaman.', $e->getMessage(), 500);
        }
    }

    /**
     * Laporan SHU sederhana per anggota (berdasarkan aktivitas).
     * Mengembalikan:
     * - total belanja anggota (POS)
     * - total biaya operasional pinjaman (proxy "bunga/fee")
     */
    public function shu(Request $request): JsonResponse
    {
        try {
            $cabangScope = $this->resolveCabangScope($request);

            $anggotaQuery = Anggota::query();
            if ($cabangScope !== null) {
                $anggotaQuery->where('id_cabang', $cabangScope);
            }

            $anggota = $anggotaQuery->get(['id_anggota', 'nama_anggota', 'id_cabang', 'status', 'nomor_anggota']);

            $result = $anggota->map(function ($a) {
                $totalBelanja = (float) TransaksiPos::where('id_anggota', $a->id_anggota)->sum('total_bayar');
                $totalBiaya = (float) Angsuran::whereHas('pinjaman', function ($q) use ($a) {
                    $q->where('id_anggota', $a->id_anggota);
                })->where('status', 'Verified')->sum('fee_bayar');

                return [
                    'id_anggota' => $a->id_anggota,
                    'nomor_anggota' => $a->nomor_anggota,
                    'nama_anggota' => $a->nama_anggota,
                    'id_cabang' => $a->id_cabang,
                    'status' => $a->status,
                    'total_belanja' => $totalBelanja,
                    'total_biaya_operasional' => $totalBiaya,
                    'skor_shu' => $totalBelanja + $totalBiaya,
                ];
            })->values();

            return $this->successResponse('Laporan SHU berhasil diambil.', [
                'scope' => [
                    'id_cabang' => $cabangScope,
                ],
                'summary' => [
                    'total_anggota' => $result->count(),
                    'total_belanja' => (float) $result->sum('total_belanja'),
                    'total_biaya_operasional' => (float) $result->sum('total_biaya_operasional'),
                ],
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal memuat laporan SHU.', $e->getMessage(), 500);
        }
    }

    /**
     * Jurnal umum (header + detail) per periode.
     * Query params: start (Y-m-d), end (Y-m-d)
     */
    public function jurnalUmum(Request $request): JsonResponse
    {
        try {
            $start = $request->query('start');
            $end = $request->query('end');
            $cabangScope = $this->resolveCabangScope($request);

            $query = Jurnal::query()->with(['detailJurnals.akun']);
            if ($cabangScope !== null) {
                $query->where('id_cabang', $cabangScope);
            }
            if ($start) {
                $query->whereDate('tanggal', '>=', $start);
            }
            if ($end) {
                $query->whereDate('tanggal', '<=', $end);
            }

            $data = $query->orderByDesc('tanggal')->orderByDesc('id_jurnal')->get();

            return $this->successResponse('Jurnal umum berhasil diambil.', [
                'filters' => [
                    'start' => $start,
                    'end' => $end,
                    'id_cabang' => $cabangScope,
                ],
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal memuat jurnal umum.', $e->getMessage(), 500);
        }
    }

    /**
     * Buku besar: mutasi per akun.
     * Query params: id_akun (required), start/end (optional)
     */
    public function bukuBesar(Request $request): JsonResponse
    {
        try {
            $idAkun = (int) $request->query('id_akun');
            if ($idAkun <= 0) {
                return $this->errorResponse('id_akun wajib diisi.', null, 422);
            }

            $start = $request->query('start');
            $end = $request->query('end');
            $cabangScope = $this->resolveCabangScope($request);

            $akun = Akun::find($idAkun);
            if (! $akun) {
                return $this->errorResponse('Akun tidak ditemukan.', null, 404);
            }

            $q = DetailJurnal::query()->with('jurnal');
            $q->where('id_akun', $idAkun);
            $q->whereHas('jurnal', function ($jq) use ($cabangScope, $start, $end) {
                if ($cabangScope !== null) {
                    $jq->where('id_cabang', $cabangScope);
                }
                if ($start) {
                    $jq->whereDate('tanggal', '>=', $start);
                }
                if ($end) {
                    $jq->whereDate('tanggal', '<=', $end);
                }
            });
            $rows = $q->orderBy('id_detail_jurnal', 'asc')->get();

            $saldo = 0.0;
            $mutasi = $rows->map(function ($r) use (&$saldo) {
                $saldo += ((float) $r->debit) - ((float) $r->kredit);
                return [
                    'tanggal' => optional($r->jurnal?->tanggal)->toDateString(),
                    'keterangan' => $r->jurnal?->keterangan,
                    'debit' => (float) $r->debit,
                    'kredit' => (float) $r->kredit,
                    'saldo' => (float) $saldo,
                ];
            })->values();

            return $this->successResponse('Buku besar berhasil diambil.', [
                'filters' => [
                    'id_akun' => $idAkun,
                    'start' => $start,
                    'end' => $end,
                    'id_cabang' => $cabangScope,
                ],
                'akun' => $akun,
                'ending_balance' => (float) $saldo,
                'data' => $mutasi,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal memuat buku besar.', $e->getMessage(), 500);
        }
    }

    /**
     * Neraca per periode (saldo akhir).
     * Query params: end (Y-m-d, optional)
     */
    public function neraca(Request $request): JsonResponse
    {
        try {
            $end = $request->query('end');
            $cabangScope = $this->resolveCabangScope($request);

            $rows = DetailJurnal::query()
                ->join('jurnals', 'jurnals.id_jurnal', '=', 'detail_jurnals.id_jurnal')
                ->join('akuns', 'akuns.id_akun', '=', 'detail_jurnals.id_akun')
                ->when($cabangScope !== null, fn ($q) => $q->where('jurnals.id_cabang', $cabangScope))
                ->when($end, fn ($q) => $q->whereDate('jurnals.tanggal', '<=', $end))
                ->groupBy('akuns.id_akun', 'akuns.nama_akun', 'akuns.jenis')
                ->selectRaw("akuns.id_akun, akuns.nama_akun, akuns.jenis, SUM(detail_jurnals.debit) as debit, SUM(detail_jurnals.kredit) as kredit")
                ->get();

            $mapped = $rows->map(function ($r) {
                $saldo = ((float) $r->debit) - ((float) $r->kredit);
                return [
                    'id_akun' => (int) $r->id_akun,
                    'nama_akun' => $r->nama_akun,
                    'jenis' => $r->jenis,
                    'saldo' => (float) $saldo,
                ];
            });

            $aset = $mapped->where('jenis', 'Aset')->values();
            $kewajiban = $mapped->where('jenis', 'Kewajiban')->values();
            $modal = $mapped->where('jenis', 'Modal')->values();

            return $this->successResponse('Neraca berhasil diambil.', [
                'filters' => [
                    'end' => $end,
                    'id_cabang' => $cabangScope,
                ],
                'aset' => $aset,
                'kewajiban' => $kewajiban,
                'modal' => $modal,
                'total_aset' => (float) $aset->sum('saldo'),
                'total_kewajiban' => (float) $kewajiban->sum('saldo'),
                'total_modal' => (float) $modal->sum('saldo'),
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal memuat neraca.', $e->getMessage(), 500);
        }
    }

    /**
     * Laba rugi per periode.
     * Query params: start/end (optional)
     */
    public function labaRugi(Request $request): JsonResponse
    {
        try {
            $start = $request->query('start');
            $end = $request->query('end');
            $cabangScope = $this->resolveCabangScope($request);

            $rows = DetailJurnal::query()
                ->join('jurnals', 'jurnals.id_jurnal', '=', 'detail_jurnals.id_jurnal')
                ->join('akuns', 'akuns.id_akun', '=', 'detail_jurnals.id_akun')
                ->when($cabangScope !== null, fn ($q) => $q->where('jurnals.id_cabang', $cabangScope))
                ->when($start, fn ($q) => $q->whereDate('jurnals.tanggal', '>=', $start))
                ->when($end, fn ($q) => $q->whereDate('jurnals.tanggal', '<=', $end))
                ->whereIn('akuns.jenis', ['Pendapatan', 'Beban'])
                ->groupBy('akuns.id_akun', 'akuns.nama_akun', 'akuns.jenis')
                ->selectRaw("akuns.id_akun, akuns.nama_akun, akuns.jenis, SUM(detail_jurnals.debit) as debit, SUM(detail_jurnals.kredit) as kredit")
                ->get();

            $mapped = $rows->map(function ($r) {
                $saldo = ((float) $r->kredit) - ((float) $r->debit); // pendapatan normal kredit, beban normal debit
                if ($r->jenis === 'Beban') {
                    $saldo = ((float) $r->debit) - ((float) $r->kredit);
                }
                return [
                    'id_akun' => (int) $r->id_akun,
                    'nama_akun' => $r->nama_akun,
                    'jenis' => $r->jenis,
                    'nilai' => (float) $saldo,
                ];
            });

            $pendapatan = $mapped->where('jenis', 'Pendapatan')->values();
            $beban = $mapped->where('jenis', 'Beban')->values();
            $totalPendapatan = (float) $pendapatan->sum('nilai');
            $totalBeban = (float) $beban->sum('nilai');

            return $this->successResponse('Laporan laba rugi berhasil diambil.', [
                'filters' => [
                    'start' => $start,
                    'end' => $end,
                    'id_cabang' => $cabangScope,
                ],
                'pendapatan' => $pendapatan,
                'beban' => $beban,
                'total_pendapatan' => $totalPendapatan,
                'total_beban' => $totalBeban,
                'laba_bersih' => $totalPendapatan - $totalBeban,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal memuat laba rugi.', $e->getMessage(), 500);
        }
    }

    /**
     * SHU final: hitung total SHU (laba bersih) periode dan bagikan proporsional ke anggota.
     * Query params: start/end (optional), shu_percent (optional, default 1.0)
     */
    public function shuFinal(Request $request): JsonResponse
    {
        try {
            $start = $request->query('start');
            $end = $request->query('end');
            $shuPercent = (float) ($request->query('shu_percent') ?? 1.0);
            if ($shuPercent <= 0 || $shuPercent > 1) {
                return $this->errorResponse('shu_percent harus di antara 0 dan 1.', null, 422);
            }

            $cabangScope = $this->resolveCabangScope($request);

            // Total SHU = laba bersih periode
            $lrResp = $this->labaRugi($request);
            $lrData = $lrResp->getData(true);
            $labaBersih = (float) ($lrData['data']['laba_bersih'] ?? 0);
            $totalShu = max(0.0, $labaBersih * $shuPercent);

            $anggotaQuery = Anggota::query()->where('status', 'Aktif');
            if ($cabangScope !== null) {
                $anggotaQuery->where('id_cabang', $cabangScope);
            }
            $anggota = $anggotaQuery->get(['id_anggota', 'nama_anggota', 'id_cabang', 'nomor_anggota']);

            $rows = $anggota->map(function ($a) use ($start, $end) {
                $belanja = TransaksiPos::query()
                    ->where('id_anggota', $a->id_anggota)
                    ->when($start, fn ($q) => $q->whereDate('tanggal_jam', '>=', $start))
                    ->when($end, fn ($q) => $q->whereDate('tanggal_jam', '<=', $end))
                    ->sum('total_bayar');

                $fee = Angsuran::query()
                    ->whereHas('pinjaman', fn ($q) => $q->where('id_anggota', $a->id_anggota))
                    ->where('status', 'Verified')
                    ->when($start, fn ($q) => $q->whereDate('tanggal_bayar', '>=', $start))
                    ->when($end, fn ($q) => $q->whereDate('tanggal_bayar', '<=', $end))
                    ->sum('fee_bayar');

                $skor = (float) $belanja + (float) $fee;

                return [
                    'id_anggota' => $a->id_anggota,
                    'nomor_anggota' => $a->nomor_anggota,
                    'nama_anggota' => $a->nama_anggota,
                    'id_cabang' => $a->id_cabang,
                    'total_belanja' => (float) $belanja,
                    'total_fee' => (float) $fee,
                    'skor' => (float) $skor,
                ];
            })->values();

            $totalSkor = (float) $rows->sum('skor');
            $pembagian = $rows->map(function ($r) use ($totalSkor, $totalShu) {
                $porsi = $totalSkor > 0 ? ((float) $r['skor'] / $totalSkor) : 0.0;
                return $r + [
                    'porsi' => $porsi,
                    'shu_didapat' => (float) ($totalShu * $porsi),
                ];
            })->values();

            return $this->successResponse('SHU final berhasil dihitung.', [
                'filters' => [
                    'start' => $start,
                    'end' => $end,
                    'shu_percent' => $shuPercent,
                    'id_cabang' => $cabangScope,
                ],
                'total_shu' => (float) $totalShu,
                'total_skor' => (float) $totalSkor,
                'data' => $pembagian,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal menghitung SHU final.', $e->getMessage(), 500);
        }
    }

    /**
     * Laporan anggota (aktif/non-aktif/calon).
     * Query params: status (optional: Aktif/Calon)
     */
    public function anggota(Request $request): JsonResponse
    {
        try {
            $status = $request->query('status');
            $cabangScope = $this->resolveCabangScope($request);

            $query = Anggota::query()->with('cabang');
            if ($cabangScope !== null) {
                $query->where('id_cabang', $cabangScope);
            }
            if ($status) {
                $query->where('status', $status);
            }

            $data = $query->orderBy('nama_anggota')->get();

            return $this->successResponse('Laporan anggota berhasil diambil.', [
                'filters' => [
                    'status' => $status,
                    'id_cabang' => $cabangScope,
                ],
                'summary' => [
                    'total' => $data->count(),
                    'aktif' => $data->where('status', 'Aktif')->count(),
                    'non_aktif' => $data->where('status', 'Non-Aktif')->count(),
                    'calon' => $data->where('status', 'Calon')->count(),
                ],
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal memuat laporan anggota.', $e->getMessage(), 500);
        }
    }

    /**
     * Laporan simpanan per anggota / keseluruhan.
     * Query params: id_anggota (optional), start/end (optional)
     */
    public function simpanan(Request $request): JsonResponse
    {
        try {
            $idAnggota = $request->query('id_anggota');
            $start = $request->query('start');
            $end = $request->query('end');
            $cabangScope = $this->resolveCabangScope($request);

            $query = Simpanan::query()->with('anggota')->where('status', 'Verified');
            if ($idAnggota !== null) {
                $query->where('id_anggota', (int) $idAnggota);
            }
            if ($start) {
                $query->whereDate('tanggal', '>=', $start);
            }
            if ($end) {
                $query->whereDate('tanggal', '<=', $end);
            }
            if ($cabangScope !== null) {
                $query->whereHas('anggota', fn ($q) => $q->where('id_cabang', $cabangScope));
            }

            $data = $query->orderByDesc('tanggal')->get();

            return $this->successResponse('Laporan simpanan berhasil diambil.', [
                'filters' => [
                    'id_anggota' => $idAnggota ? (int) $idAnggota : null,
                    'start' => $start,
                    'end' => $end,
                    'id_cabang' => $cabangScope,
                ],
                'summary' => [
                    'total_transaksi' => $data->count(),
                    'total_simpanan' => (float) $data->sum('jumlah'),
                    'pokok' => (float) $data->where('jenis_simpanan', 'Pokok')->sum('jumlah'),
                    'wajib' => (float) $data->where('jenis_simpanan', 'Wajib')->sum('jumlah'),
                    'sukarela' => (float) $data->where('jenis_simpanan', 'Sukarela')->sum('jumlah'),
                ],
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal memuat laporan simpanan.', $e->getMessage(), 500);
        }
    }

    /**
     * Laporan persediaan/stok.
     * Menandai warning saat stok < 100.
     */
    public function persediaan(Request $request): JsonResponse
    {
        try {
            $threshold = (int) ($request->query('threshold') ?? config('koperasi.stok_warning_threshold', 100));
            if ($threshold <= 0) {
                $threshold = 100;
            }

            $cabangScope = $this->resolveCabangScope($request);
            $produkQuery = Produk::query();
            if ($cabangScope !== null) {
                $produkQuery->where(function ($q) use ($cabangScope) {
                    $q->where('id_cabang', $cabangScope)->orWhereNull('id_cabang');
                });
            }
            $produks = $produkQuery->orderBy('nama_produk')->get();

            $data = $produks->map(function ($p) use ($threshold) {
                $stok = (int) $p->stok;
                return [
                    'id_produk' => $p->id_produk,
                    'nama_produk' => $p->nama_produk,
                    'harga_beli' => (float) $p->harga_beli,
                    'harga_jual' => (float) $p->harga_jual,
                    'stok' => $stok,
                    'is_low_stock' => $stok < $threshold,
                    'nilai_persediaan' => (float) ($stok * (float) $p->harga_beli),
                ];
            })->values();

            return $this->successResponse('Laporan persediaan berhasil diambil.', [
                'filters' => [
                    'threshold' => $threshold,
                    'id_cabang' => $cabangScope,
                ],
                'summary' => [
                    'total_produk' => $data->count(),
                    'stok_kritis' => $data->where('is_low_stock', true)->count(),
                    'total_nilai_persediaan' => (float) $data->sum('nilai_persediaan'),
                ],
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal memuat laporan persediaan.', $e->getMessage(), 500);
        }
    }
}
