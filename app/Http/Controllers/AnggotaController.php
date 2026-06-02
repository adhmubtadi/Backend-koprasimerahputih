<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActivateAnggotaRequest;
use App\Http\Requests\RegisterAnggotaRequest;
use App\Http\Requests\UpdateAnggotaRequest;
use App\Http\Resources\AnggotaResource;
use App\Models\Account;
use App\Models\Anggota;
use App\Models\Simpanan;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnggotaController extends Controller
{
    use ApiResponse;

    /**
     * Pendaftaran calon anggota (public).
     * Membuat akun login + profil anggota status Calon.
     */
    public function register(RegisterAnggotaRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = DB::transaction(function () use ($validated) {
            $account = Account::create([
                'username' => $validated['username'],
                'password' => $validated['password'],
                'role' => 'Anggota',
            ]);

            $anggota = Anggota::create([
                'id_account' => $account->id_account,
                'nama_anggota' => $validated['nama_anggota'],
                'alamat' => $validated['alamat'],
                'no_hp' => $validated['no_hp'],
                'email' => $validated['email'],
                'id_cabang' => $validated['id_cabang'],
                'tanggal_daftar' => now()->toDateString(),
                'status' => 'Calon',
            ]);

            return $anggota->load(['account', 'cabang']);
        });

        return $this->successResponse(
            'Pendaftaran berhasil. Menunggu aktivasi admin.',
            new AnggotaResource($result),
            201
        );
    }

    /**
     * Profil anggota yang sedang login.
     */
    public function me(Request $request): JsonResponse
    {
        $anggota = $request->user()?->anggota;

        if (! $anggota) {
            return $this->errorResponse('Profil anggota tidak ditemukan.', null, 404);
        }

        $anggota->load(['account', 'cabang', 'simpanans', 'pinjamans']);

        return $this->successResponse('Profil anggota berhasil diambil.', new AnggotaResource($anggota));
    }

    /**
     * Daftar anggota (Pengurus/Admin).
     * Query: status, id_cabang, q (cari nama/email/nomor)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Anggota::query()->with(['account', 'cabang']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('id_cabang')) {
            $query->where('id_cabang', (int) $request->query('id_cabang'));
        }

        if ($request->filled('q')) {
            $term = '%'.$request->string('q').'%';
            $query->where(function ($q) use ($term) {
                $q->where('nama_anggota', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('nomor_anggota', 'like', $term);
            });
        }

        $anggota = $query->orderByDesc('id_anggota')->get();

        return $this->successResponse(
            'Daftar anggota berhasil diambil.',
            AnggotaResource::collection($anggota)
        );
    }

    /**
     * Detail anggota + riwayat simpanan, pinjaman, transaksi POS.
     */
    public function show(int $id_anggota): JsonResponse
    {
        $anggota = Anggota::with(['account', 'cabang', 'simpanans', 'pinjamans', 'transaksiPos'])
            ->find($id_anggota);

        if (! $anggota) {
            return $this->errorResponse('Anggota tidak ditemukan.', null, 404);
        }

        return $this->successResponse('Detail anggota berhasil diambil.', new AnggotaResource($anggota));
    }

    /**
     * Admin/Pengurus: tambah anggota (sama seperti register).
     */
    public function store(RegisterAnggotaRequest $request): JsonResponse
    {
        return $this->register($request);
    }

    public function update(UpdateAnggotaRequest $request, int $id_anggota): JsonResponse
    {
        $anggota = Anggota::find($id_anggota);

        if (! $anggota) {
            return $this->errorResponse('Anggota tidak ditemukan.', null, 404);
        }

        $anggota->update($request->validated());
        $anggota->load(['account', 'cabang']);

        return $this->successResponse('Data anggota berhasil diperbarui.', new AnggotaResource($anggota));
    }

    public function destroy(int $id_anggota): JsonResponse
    {
        $anggota = Anggota::with('account')->find($id_anggota);

        if (! $anggota) {
            return $this->errorResponse('Anggota tidak ditemukan.', null, 404);
        }

        DB::transaction(function () use ($anggota) {
            $account = $anggota->account;
            $anggota->delete();

            if ($account) {
                $account->delete();
            }
        });

        return $this->successResponse('Anggota berhasil dihapus.', null);
    }

    /**
     * Aktivasi calon anggota (Admin only).
     */
    public function activate(ActivateAnggotaRequest $request, int $id_anggota): JsonResponse
    {
        $anggota = Anggota::with(['account', 'cabang'])->find($id_anggota);

        if (! $anggota) {
            return $this->errorResponse('Anggota tidak ditemukan.', null, 404);
        }

        if ($anggota->status !== 'Calon') {
            return $this->errorResponse(
                'Hanya anggota berstatus Calon yang bisa diaktifkan.',
                new AnggotaResource($anggota),
                422
            );
        }

        $result = DB::transaction(function () use ($request, $anggota) {
            $nomor = 'AGT-'.(int) $anggota->id_cabang.'-'.str_pad((string) $anggota->id_anggota, 6, '0', STR_PAD_LEFT);

            if (Anggota::where('nomor_anggota', $nomor)->where('id_anggota', '!=', $anggota->id_anggota)->exists()) {
                $nomor = $nomor.'-'.now()->format('His');
            }

            $anggota->nomor_anggota = $nomor;
            $anggota->status = 'Aktif';
            $anggota->tanggal_daftar = $anggota->tanggal_daftar ?? now()->toDateString();
            $anggota->save();

            $validated = $request->validated();
            $simpanan = Simpanan::create([
                'id_anggota' => $anggota->id_anggota,
                'jenis_simpanan' => 'Pokok',
                'jumlah' => (float) $validated['simpanan_pokok'],
                'tanggal' => $validated['tanggal'] ?? now()->toDateString(),
            ]);

            return [
                'anggota' => $anggota->fresh(['account', 'cabang']),
                'simpanan_pokok' => $simpanan,
            ];
        });

        return $this->successResponse('Anggota berhasil diaktifkan dan simpanan pokok tercatat.', [
            'anggota' => new AnggotaResource($result['anggota']),
            'simpanan_pokok' => $result['simpanan_pokok'],
        ]);
    }
}
