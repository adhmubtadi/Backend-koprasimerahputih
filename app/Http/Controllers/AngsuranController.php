<?php

namespace App\Http\Controllers;

use App\Models\Angsuran;
use App\Models\Pinjaman;
use App\Models\Simpanan;
use App\Services\JurnalService;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\JsonResponse;

class AngsuranController extends Controller
{
    use ApiResponse;

    // 1. Anggota upload pembayaran (Status Pending)
    public function store(Request $request)
    {
        $request->validate([
            'id_pinjaman' => 'required|exists:pinjamans,id_pinjaman',
            'jumlah_bayar' => 'required|numeric',
            'bukti_transfer' => 'required|file|mimes:jpg,png,jpeg,pdf|max:2048',
        ]);

        $path = $request->file('bukti_transfer')->store('bukti_pembayaran', 'public');

        $angsuran = Angsuran::create([
            'id_pinjaman' => $request->id_pinjaman,
            'jumlah_bayar' => $request->jumlah_bayar,
            'tanggal_bayar' => now(),
            'bukti_transfer' => $path,
            'status' => 'Pending',
            'sisa_pinjaman' => 0,
        ]);

        return $this->successResponse('Bukti transfer berhasil diupload, mohon tunggu verifikasi.', $angsuran);
    }

    // 2. Admin Verifikasi Pembayaran
    public function verify($id_angsuran)
    {
        return DB::transaction(function () use ($id_angsuran) {
            $angsuran = Angsuran::lockForUpdate()->findOrFail($id_angsuran);
            
            if ($angsuran->status !== 'Pending') {
                return $this->errorResponse('Pembayaran ini sudah diproses.', 400);
            }

            $pinjaman = Pinjaman::lockForUpdate()->findOrFail($angsuran->id_pinjaman);

            $lastVerified = Angsuran::where('id_pinjaman', $pinjaman->id_pinjaman)
                ->where('status', 'Verified')
                ->orderBy('id_angsuran', 'desc')
                ->first();

            $saldoSaatIni = (float) ($lastVerified ? $lastVerified->sisa_pinjaman : $pinjaman->jumlah_pinjaman);
            $jumlahBayar = (float) $angsuran->jumlah_bayar;

            // Biaya operasional sesuai dokumen: 2% dari total pinjaman.
            $totalFee = round(((float) $pinjaman->jumlah_pinjaman) * 0.02, 2);
            $feeSudahDibayar = (float) Angsuran::where('id_pinjaman', $pinjaman->id_pinjaman)
                ->where('status', 'Verified')
                ->sum('fee_bayar');
            $feeSisa = max(0.0, $totalFee - $feeSudahDibayar);

            $totalKewajiban = $saldoSaatIni + $feeSisa;
            $alokasiKePinjaman = min($jumlahBayar, $totalKewajiban);
            $kelebihanBayar = max(0.0, $jumlahBayar - $totalKewajiban);

            $feeBayar = min($feeSisa, $alokasiKePinjaman);
            $pokokBayar = $alokasiKePinjaman - $feeBayar;
            $sisaBaru = max(0.0, $saldoSaatIni - $pokokBayar);

            $angsuran->update([
                'status' => 'Verified',
                'pokok_bayar' => $pokokBayar,
                'fee_bayar' => $feeBayar,
                'sisa_pinjaman' => $sisaBaru,
            ]);

            // Samakan nilai biaya operasional pinjaman dengan 2% pokok pinjaman.
            if ((float) ($pinjaman->biaya_operasional ?? 0) !== $totalFee) {
                $pinjaman->biaya_operasional = $totalFee;
                $pinjaman->save();
            }

            if ($sisaBaru <= 0) {
                // Status "Lunas" tidak ada di enum pinjamans saat ini.
                // Biarkan status Approved, pelunasan dibaca dari sisa_pinjaman=0.
            }

            // Catat jurnal angsuran untuk nominal yang benar-benar dialokasikan.
            /** @var JurnalService $jurnal */
            $jurnal = app(JurnalService::class);
            if ($pokokBayar > 0) {
                $jurnal->catatAngsuranMasukNominal($angsuran, $pokokBayar);
            }
            if ($feeBayar > 0) {
                $jurnal->catatBiayaOperasionalAngsuran($angsuran, $feeBayar);
            }

            $simpananSukarela = null;
            if ($kelebihanBayar > 0) {
                // Kelebihan pembayaran otomatis jadi Simpanan Sukarela anggota.
                $simpananSukarela = Simpanan::create([
                    'id_anggota' => $pinjaman->id_anggota,
                    'jenis_simpanan' => 'Sukarela',
                    'jumlah' => $kelebihanBayar,
                    'tanggal' => now()->toDateString(),
                ]);
            }

            return $this->successResponse('Pembayaran berhasil diverifikasi.', [
                'id_angsuran' => $angsuran->id_angsuran,
                'alokasi_pokok' => (float) $pokokBayar,
                'alokasi_fee' => (float) $feeBayar,
                'kelebihan_masuk_simpanan_sukarela' => (float) $kelebihanBayar,
                'sisa_pinjaman' => (float) $sisaBaru,
                'simpanan_sukarela' => $simpananSukarela,
            ]);
        });
    }

    public function reject($id_angsuran): JsonResponse
    {
        $angsuran = Angsuran::findOrFail($id_angsuran);

        if ($angsuran->status !== 'Pending') {
            return $this->errorResponse('Pembayaran ini sudah diproses.', null, 400);
        }

        $angsuran->update(['status' => 'Rejected']);

        return $this->successResponse('Pembayaran berhasil ditolak.', $angsuran->fresh('pinjaman'));
    }

    public function bukti(Request $request, $id_angsuran)
    {
        $angsuran = Angsuran::with('pinjaman')->findOrFail($id_angsuran);
        $user = $request->user();

        if ($user->role === 'Anggota') {
            $anggota = $user->anggota;
            if (! $anggota || $angsuran->pinjaman?->id_anggota !== $anggota->id_anggota) {
                return $this->errorResponse('Anda tidak punya akses ke bukti pembayaran ini.', null, 403);
            }
        }

        if (! $angsuran->bukti_transfer || ! Storage::disk('public')->exists($angsuran->bukti_transfer)) {
            return $this->errorResponse('File bukti pembayaran tidak ditemukan.', null, 404);
        }

        return response()->file(Storage::disk('public')->path($angsuran->bukti_transfer));
    }

    // 3. Lihat Riwayat Angsuran & Statusnya
    public function history(Request $request)
    {
        $user = $request->user();
        $query = Angsuran::with('pinjaman');

        if ($user->role === 'Anggota') {
            $query->whereHas('pinjaman', function($q) use ($user) {
                $q->where('id_anggota', $user->anggota->id_anggota);
            });
        }

        $data = $query->orderBy('id_angsuran', 'desc')->get();
        
        $data->map(function ($item) {
            $item->url_bukti = $item->bukti_transfer ? asset('storage/' . $item->bukti_transfer) : null;
            return $item;
        });

        return $this->successResponse('Riwayat angsuran berhasil dimuat.', $data);
    }

    // 4. Cek Sisa Pinjaman Spesifik
    public function checkSisa($id_pinjaman)
    {
        $pinjaman = Pinjaman::findOrFail($id_pinjaman);
        
        $lastVerified = Angsuran::where('id_pinjaman', $id_pinjaman)
            ->where('status', 'Verified')
            ->orderBy('id_angsuran', 'desc')
            ->first();

        $sisa = $lastVerified ? $lastVerified->sisa_pinjaman : $pinjaman->jumlah_pinjaman;

        return $this->successResponse('Informasi saldo pinjaman.', [
            'id_pinjaman' => $id_pinjaman,
            'total_hutang_awal' => (float) $pinjaman->jumlah_pinjaman,
            'sisa_hutang_saat_ini' => (float) $sisa,
            'status_pinjaman' => $pinjaman->status
        ]);
    }
}
