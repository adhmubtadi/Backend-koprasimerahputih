<?php

namespace App\Http\Controllers;

use App\Models\Produk;
use App\Traits\ApiResponse;
use App\Traits\ResolvesCabangScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProdukController extends Controller
{
    use ApiResponse, ResolvesCabangScope;

    /**
     * List produk — Kasir: terbatas untuk transaksi penjualan.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Produk::with(['supplier', 'cabang']);
        $cabangScope = $this->resolveCabangScope($request);

        if ($cabangScope !== null) {
            $query->where(function ($q) use ($cabangScope) {
                $q->where('id_cabang', $cabangScope)->orWhereNull('id_cabang');
            });
        }

        if ($request->has('search')) {
            $query->where('nama_produk', 'like', '%'.$request->search.'%');
        }

        if ($request->has('id_supplier')) {
            $query->where('id_supplier', $request->id_supplier);
        }

        $threshold = (int) config('koperasi.stok_warning_threshold', 100);
        $produk = $query->orderBy('nama_produk', 'asc')->get();

        $isKasir = $request->user()?->role === 'Kasir';

        $produk->transform(function ($item) use ($threshold, $isKasir) {
            $item->is_low_stock = ((int) ($item->stok ?? 0)) < $threshold;

            if ($isKasir) {
                return [
                    'id_produk' => $item->id_produk,
                    'nama_produk' => $item->nama_produk,
                    'harga_jual' => (float) $item->harga_jual,
                    'stok' => (int) $item->stok,
                    'is_low_stock' => $item->is_low_stock,
                ];
            }

            return $item;
        });

        return $this->successResponse('Daftar produk koperasi', $produk);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_cabang' => 'required|exists:cabangs,id_cabang',
            'id_supplier' => 'required|exists:suppliers,id_supplier',
            'nama_produk' => 'required|string|max:255',
            'harga_beli' => 'required|numeric|min:0',
            'harga_jual' => 'required|numeric|min:0',
            'stok' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validasi gagal', $validator->errors(), 422);
        }

        if ($request->harga_jual < $request->harga_beli) {
            return $this->errorResponse('Harga jual tidak boleh lebih rendah dari harga beli!', null, 400);
        }

        $cabangScope = $this->resolveCabangScope($request);
        if ($cabangScope !== null && (int) $request->id_cabang !== $cabangScope) {
            return $this->errorResponse('Produk hanya bisa ditambahkan untuk cabang Anda.', null, 403);
        }

        $produk = Produk::create($request->only([
            'id_cabang', 'id_supplier', 'nama_produk', 'harga_beli', 'harga_jual', 'stok',
        ]));

        return $this->successResponse('Produk berhasil ditambahkan', $produk, 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $produk = Produk::with(['supplier', 'cabang'])->where('id_produk', $id)->first();

        if (! $produk) {
            return $this->errorResponse('Produk tidak ditemukan', null, 404);
        }

        $cabangScope = $this->resolveCabangScope($request);
        if ($cabangScope !== null && $produk->id_cabang && (int) $produk->id_cabang !== $cabangScope) {
            return $this->errorResponse('Produk tidak ditemukan di cabang Anda.', null, 404);
        }

        return $this->successResponse('Detail produk', $produk);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $produk = Produk::where('id_produk', $id)->first();
        if (! $produk) {
            return $this->errorResponse('Produk tidak ditemukan', null, 404);
        }

        $cabangScope = $this->resolveCabangScope($request);
        if ($cabangScope !== null && $produk->id_cabang && (int) $produk->id_cabang !== $cabangScope) {
            return $this->errorResponse('Produk tidak ditemukan di cabang Anda.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'id_supplier' => 'sometimes|exists:suppliers,id_supplier',
            'nama_produk' => 'sometimes|string|max:255',
            'harga_beli' => 'sometimes|numeric|min:0',
            'harga_jual' => 'sometimes|numeric|min:0',
            'stok' => 'sometimes|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validasi gagal', $validator->errors(), 422);
        }

        $produk->update($request->all());

        return $this->successResponse('Produk berhasil diupdate', $produk);
    }

    public function destroy(int $id): JsonResponse
    {
        $produk = Produk::where('id_produk', $id)->first();
        if (! $produk) {
            return $this->errorResponse('Produk tidak ditemukan', null, 404);
        }

        $produk->delete();

        return $this->successResponse('Produk berhasil dihapus', null);
    }
}
