<?php

namespace App\Http\Controllers;

use App\Models\Cabang;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class CabangController extends Controller
{
    use ApiResponse;

    /**
     * Daftar 20 cabang: Bandung 5, Jakarta 5, Bekasi 5, Serang 3, Cilegon 2.
     */
    public function index(): JsonResponse
    {
        $cabangs = Cabang::query()
            ->orderBy('kota')
            ->orderBy('nama_cabang')
            ->get(['id_cabang', 'nama_cabang', 'kota', 'lokasi']);

        $perKota = $cabangs->groupBy('kota')->map(fn ($items, $kota) => [
            'kota' => $kota,
            'jumlah' => $items->count(),
            'cabang' => $items->values(),
        ])->values();

        return $this->successResponse('Daftar cabang berhasil diambil.', [
            'total' => $cabangs->count(),
            'distribusi' => $perKota,
            'data' => $cabangs,
        ]);
    }
}
