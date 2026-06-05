<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApproveUsulanRequest;
use App\Models\Gudang;
use App\Models\UsulanStok;
use App\Traits\ApiResponse;
use App\Traits\ResolvesCabangScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class UsulanStokController extends Controller
{
    use ApiResponse, ResolvesCabangScope;

    public function index(Request $request): JsonResponse
    {
        $query = UsulanStok::with(['produk', 'gudang', 'supplier', 'cabang', 'pengurusAcc']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $cabangScope = $this->resolveCabangScope($request);
        if ($cabangScope !== null) {
            $query->where('id_cabang', $cabangScope);
        }

        $data = $query->orderByDesc('tanggal_usulan')->get();

        return $this->successResponse('Daftar usulan stok berhasil diambil.', $data);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_produk' => 'required|exists:produks,id_produk',
            'id_supplier' => 'required|exists:suppliers,id_supplier',
            'jumlah' => 'required|integer|min:1',
            'harga_beli' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validasi gagal', $validator->errors(), 422);
        }

        $petugas = Gudang::where('id_account', Auth::user()->id_account)->first();

        if (! $petugas) {
            return $this->errorResponse('Anda tidak terdaftar sebagai petugas gudang.', null, 403);
        }

        $usulan = UsulanStok::create([
            'id_produk' => $request->id_produk,
            'id_gudang' => $petugas->id_gudang,
            'id_supplier' => $request->id_supplier,
            'id_cabang' => $petugas->id_cabang,
            'id_pengurus_acc' => null,
            'jumlah' => $request->jumlah,
            'harga_beli' => $request->harga_beli,
            'status' => 'Pending',
            'tanggal_usulan' => now()->toDateString(),
        ]);

        return $this->successResponse(
            'Usulan pembelian barang ke distributor berhasil diajukan.',
            $usulan->load(['produk', 'supplier', 'cabang']),
            201
        );
    }

    /**
     * Pengurus ACC usulan, tentukan harga jual, update stok.
     */
    public function approveUsulan(ApproveUsulanRequest $request, int $id_usulan): JsonResponse
    {
        $validated = $request->validated();
        $hargaJual = (float) $validated['harga_jual'];

        DB::beginTransaction();

        try {
            $usulan = UsulanStok::lockForUpdate()->with('produk')->find($id_usulan);

            if (! $usulan) {
                return $this->errorResponse('Usulan tidak ditemukan.', null, 404);
            }

            if ($usulan->status !== 'Pending') {
                return $this->errorResponse('Hanya usulan "Pending" yang bisa disetujui.', null, 422);
            }

            if ($hargaJual < (float) $usulan->harga_beli) {
                return $this->errorResponse('Harga jual tidak boleh lebih rendah dari harga beli.', null, 422);
            }

            $pengurus = $request->user()?->pengurus;
            if (! $pengurus) {
                DB::rollBack();
                return $this->errorResponse('Akun pengurus tidak valid.', null, 422);
            }

            $cabangScope = $this->resolveCabangScope($request);
            if ($cabangScope !== null && (int) $usulan->id_cabang !== $cabangScope) {
                DB::rollBack();
                return $this->errorResponse('Usulan ini bukan dari cabang Anda.', null, 403);
            }

            $usulan->status = 'ACC';
            $usulan->harga_jual = $hargaJual;
            $usulan->id_pengurus_acc = $pengurus->id_pengurus;
            $usulan->save();

            $produk = $usulan->produk;
            if (! $produk) {
                DB::rollBack();
                return $this->errorResponse('Produk untuk usulan ini tidak ditemukan.', null, 422);
            }

            $produk->stok = (int) ($produk->stok ?? 0) + (int) $usulan->jumlah;
            $produk->harga_beli = (float) $usulan->harga_beli;
            $produk->harga_jual = $hargaJual;
            if ($usulan->id_cabang) {
                $produk->id_cabang = $usulan->id_cabang;
            }
            $produk->save();

            DB::commit();

            return $this->successResponse(
                'Usulan stok berhasil di-ACC. Harga jual ditetapkan dan stok diperbarui.',
                $usulan->fresh()->load(['produk', 'supplier', 'cabang', 'pengurusAcc'])
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->errorResponse('Terjadi kesalahan.', $e->getMessage(), 500);
        }
    }
}
