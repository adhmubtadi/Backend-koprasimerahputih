<?php

namespace App\Http\Controllers;

use App\Models\Cabang;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class CabangController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $cabangs = Cabang::query()
            ->orderBy('nama_cabang')
            ->get(['id_cabang', 'nama_cabang', 'lokasi']);

        return $this->successResponse('Daftar cabang berhasil diambil.', $cabangs);
    }
}
