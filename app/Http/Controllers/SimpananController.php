<?php

namespace App\Http\Controllers;

use App\Models\Anggota;
use App\Models\Simpanan;
use App\Traits\ApiResponse;
use App\Traits\ResolvesCabangScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SimpananController extends Controller
{
    use ApiResponse, ResolvesCabangScope;

    public function index(Request $request): JsonResponse
    {
        $query = Simpanan::with('anggota');

        if ($request->has('id_anggota')) {
            $query->where('id_anggota', $request->id_anggota);
        }

        if ($request->has('jenis')) {
            $query->where('jenis_simpanan', $request->jenis);
        }

        $cabangScope = $this->resolveCabangScope($request);
        if ($cabangScope !== null) {
            $query->whereHas('anggota', fn ($q) => $q->where('id_cabang', $cabangScope));
        }

        if ($request->user()?->role === 'Anggota') {
            $query->where('id_anggota', $request->user()->anggota->id_anggota);
        }

        $data = $query->orderByDesc('tanggal')->get();

        return $this->successResponse('Riwayat simpanan berhasil diambil.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_anggota' => 'required|exists:anggotas,id_anggota',
            'jenis_simpanan' => 'required|in:Pokok,Wajib,Sukarela',
            'jumlah' => 'required|numeric|min:0',
            'tanggal' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validasi gagal.', $validator->errors(), 422);
        }

        $anggota = Anggota::find($request->id_anggota);
        if ($anggota?->status !== 'Aktif') {
            return $this->errorResponse('Simpanan hanya dapat dicatat untuk anggota aktif.', null, 422);
        }

        $simpanan = Simpanan::create([
            'id_anggota' => $request->id_anggota,
            'jenis_simpanan' => $request->jenis_simpanan,
            'jumlah' => $request->jumlah,
            'tanggal' => $request->tanggal ?? now()->format('Y-m-d'),
        ]);

        return $this->successResponse('Simpanan berhasil dicatat.', $simpanan, 201);
    }

    public function cekSaldo(int $id_anggota): JsonResponse
    {
        $anggota = Anggota::find($id_anggota);
        if (! $anggota) {
            return $this->errorResponse('Anggota tidak ditemukan.', null, 404);
        }

        $totalSaldo = Simpanan::where('id_anggota', $id_anggota)->sum('jumlah');

        $rincian = Simpanan::where('id_anggota', $id_anggota)
            ->selectRaw('jenis_simpanan, SUM(jumlah) as subtotal')
            ->groupBy('jenis_simpanan')
            ->get();

        return $this->successResponse('Saldo simpanan berhasil diambil.', [
            'id_anggota' => $anggota->id_anggota,
            'nomor_anggota' => $anggota->nomor_anggota,
            'nama_anggota' => $anggota->nama_anggota,
            'total_saldo' => (float) $totalSaldo,
            'rincian' => $rincian,
        ]);
    }
}
