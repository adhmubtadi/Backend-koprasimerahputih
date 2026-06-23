<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActivateAnggotaRequest;
use App\Http\Requests\RegisterAnggotaRequest;
use App\Http\Requests\UpdateAnggotaRequest;
use App\Http\Resources\AnggotaResource;
use App\Models\Account;
use App\Models\Anggota;
use App\Models\Simpanan;
use App\Services\UserDirectoryService;
use App\Traits\ApiResponse;
use App\Traits\ResolvesCabangScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnggotaController extends Controller
{
    use ApiResponse, ResolvesCabangScope;

    /**
     * Pendaftaran calon anggota (public).
     * Membuat akun login + profil anggota status Tertunda.
     */
    public function register(RegisterAnggotaRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = DB::transaction(function () use ($validated) {
            $account = Account::create([
                'username' => $validated['username'],
                'password' => $validated['password'],
                'role' => 'Anggota',
                'email' => $validated['email'],
            ]);

            $anggota = Anggota::create([
                'id_account' => $account->id_account,
                'nama_anggota' => $validated['nama_anggota'],
                'alamat' => $validated['alamat'],
                'no_hp' => $validated['no_hp'],
                'email' => $validated['email'],
                'id_cabang' => $validated['id_cabang'],
                'tanggal_daftar' => now()->toDateString(),
                'status' => 'Tertunda',
            ]);

            return $anggota->load(['account', 'cabang']);
        });

        return $this->successResponse(
            'Pendaftaran berhasil. Menunggu persetujuan admin.',
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
     * Menyajikan daftar terpadu semua peran untuk Admin/Pengurus.
     */
    public function index(Request $request): JsonResponse
    {
        $service = app(UserDirectoryService::class);
        $paginator = $service->listMembersForAdmin([
            'q' => $request->query('q'),
            'role' => $request->query('role'),
            'status' => $request->query('status'),
            'page' => $request->query('page', 1),
        ], 1000);

        return $this->successResponse(
            'Daftar anggota berhasil diambil.',
            $paginator->items()
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
     * Admin/Pengurus: tambah anggota (mendukung peran staff dan auto-generate username/password).
     */
    public function store(Request $request): JsonResponse
    {
        // Penyelarasan field dari frontend ke backend validation
        if (! $request->has('alamat') && $request->has('nama_cabang')) {
            $request->merge(['alamat' => $request->input('nama_cabang')]);
        }

        if (! $request->has('id_cabang')) {
            $firstCabang = \App\Models\Cabang::value('id_cabang') ?? 1;
            $request->merge(['id_cabang' => $firstCabang]);
        }

        if (! $request->has('telepon') && $request->has('no_hp')) {
            $request->merge(['telepon' => $request->input('no_hp')]);
        }

        $validated = $request->validate([
            'nama' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:anggotas,email',
            'alamat' => 'required|string',
            'telepon' => 'required|string|max:15',
            'id_cabang' => 'required|integer|exists:cabangs,id_cabang',
            'role' => 'required|in:Admin,Pengurus,Kasir,Gudang,Anggota',
            'status' => 'nullable|in:Aktif,Tertunda,Tidak Aktif,Ditolak',
            'username' => 'nullable|string|min:3|max:50|alpha_dash|unique:accounts,username',
            'password' => 'nullable|string|min:8',
        ]);

        if (empty($validated['username'])) {
            $baseUsername = strtolower(explode('@', $validated['email'])[0]);
            $username = $baseUsername;
            $counter = 1;
            while (Account::where('username', $username)->exists()) {
                $username = $baseUsername . $counter;
                $counter++;
            }
            $validated['username'] = $username;
        }

        if (empty($validated['password'])) {
            $validated['password'] = 'Koperasi123!';
        }

        $data = [
            'username' => $validated['username'],
            'password' => $validated['password'],
            'role' => $validated['role'],
            'nama' => $validated['nama'],
            'alamat' => $validated['alamat'],
            'no_hp' => $validated['telepon'],
            'email' => $validated['email'],
            'id_cabang' => $validated['id_cabang'],
            'status' => $validated['status'] ?? 'Aktif',
        ];

        try {
            $service = app(UserDirectoryService::class);
            $result = $service->createMember($data);
            
            $member = $service->mapAccountToMember($result['account']->load([
                'admin', 'pengurus.cabang', 'kasir.cabang', 'gudang.cabang', 'anggota.cabang'
            ]));

            return $this->successResponse('Pengguna berhasil ditambahkan.', $member, 201);
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal menambah pengguna.', $e->getMessage(), 422);
        }
    }

    public function update(Request $request, int $id_anggota): JsonResponse
    {
        $validated = $request->validate([
            'nama' => 'sometimes|string|max:255',
            'nama_anggota' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255',
            'role' => 'sometimes|in:Admin,Pengurus,Kasir,Gudang,Anggota',
            'status' => 'sometimes|in:Calon,Aktif,Non-Aktif,Tertunda,Tidak Aktif,Ditolak',
            'telepon' => 'sometimes|nullable|string|max:15',
            'no_hp' => 'sometimes|string|max:15',
            'alamat' => 'sometimes|string',
            'nama_cabang' => 'sometimes|nullable|string',
            'id_cabang' => 'sometimes|integer|exists:cabangs,id_cabang',
        ]);

        $data = [];
        if ($request->has('nama')) $data['nama'] = $request->input('nama');
        if ($request->has('nama_anggota')) $data['nama'] = $request->input('nama_anggota');
        if ($request->has('email')) $data['email'] = $request->input('email');
        if ($request->has('role')) $data['role'] = $request->input('role');
        if ($request->has('status')) $data['status'] = $request->input('status');
        if ($request->has('telepon')) $data['no_hp'] = $request->input('telepon');
        if ($request->has('no_hp')) $data['no_hp'] = $request->input('no_hp');
        if ($request->has('alamat')) $data['alamat'] = $request->input('alamat');
        if ($request->has('nama_cabang')) $data['alamat'] = $request->input('nama_cabang');
        if ($request->has('id_cabang')) $data['id_cabang'] = $request->input('id_cabang');

        $anggota = Anggota::where('id_account', $id_anggota)->orWhere('id_anggota', $id_anggota)->first();

        if (! $anggota) {
            $account = Account::find($id_anggota);
            if ($account) {
                $service = app(UserDirectoryService::class);
                $result = $service->updateMember($account->id_account, $data);
                return $this->successResponse('Data pengguna berhasil diperbarui.', $result['member']);
            }
            return $this->errorResponse('Anggota tidak ditemukan.', null, 404);
        }

        // Apply fields directly for Anggota
        if (isset($data['nama'])) $anggota->nama_anggota = $data['nama'];
        if (isset($data['email'])) $anggota->email = $data['email'];
        if (isset($data['alamat'])) $anggota->alamat = $data['alamat'];
        if (isset($data['no_hp'])) $anggota->no_hp = $data['no_hp'];
        if (isset($data['id_cabang'])) $anggota->id_cabang = $data['id_cabang'];
        if (isset($data['status'])) {
            $anggota->status = $data['status'] === 'Tidak Aktif' ? 'Non-Aktif' : $data['status'];
            if ($anggota->status === 'Aktif' && ! $anggota->nomor_anggota) {
                $anggota->nomor_anggota = 'AGT-'.$anggota->id_cabang.'-'.str_pad((string) $anggota->id_anggota, 6, '0', STR_PAD_LEFT);
            }
        }
        $anggota->save();
        $anggota->load(['account', 'cabang']);

        // Format to match expected frontend structure
        $service = app(UserDirectoryService::class);
        $mapped = $service->mapAccountToMember($anggota->account);

        return $this->successResponse('Data anggota berhasil diperbarui.', $mapped);
    }

    public function destroy(int $id_anggota): JsonResponse
    {
        $anggota = Anggota::where('id_account', $id_anggota)->orWhere('id_anggota', $id_anggota)->first();

        if (! $anggota) {
            $account = Account::find($id_anggota);
            if ($account) {
                $service = app(UserDirectoryService::class);
                $service->deleteMember($account->id_account);
                return $this->successResponse('Pengguna berhasil dihapus.', null);
            }
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
     * Menyetujui pendaftaran anggota baru (status Tertunda -> Aktif)
     * dan menggenerasi Nomor Anggota KP-XXX serta mencatat simpanan pokok perdana.
     */
    public function approve(int $id): JsonResponse
    {
        $anggota = Anggota::where('id_account', $id)->orWhere('id_anggota', $id)->first();

        if (! $anggota) {
            return $this->errorResponse('Anggota tidak ditemukan.', null, 404);
        }

        if ($anggota->status !== 'Tertunda' && $anggota->status !== 'Calon') {
            return $this->errorResponse(
                'Hanya anggota dengan status Tertunda yang bisa disetujui.',
                null,
                422
            );
        }

        $result = DB::transaction(function () use ($anggota) {
            $count = Anggota::whereNotNull('nomor_anggota')->count() + 1;
            $nomor = 'KP-' . str_pad((string) $count, 3, '0', STR_PAD_LEFT);

            while (Anggota::where('nomor_anggota', $nomor)->exists()) {
                $count++;
                $nomor = 'KP-' . str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            }

            $anggota->nomor_anggota = $nomor;
            $anggota->status = 'Aktif';
            $anggota->tanggal_daftar = $anggota->tanggal_daftar ?? now()->toDateString();
            $anggota->save();

            $hasSimpanan = Simpanan::where('id_anggota', $anggota->id_anggota)
                ->where('jenis_simpanan', 'Pokok')
                ->exists();

            if (!$hasSimpanan) {
                Simpanan::create([
                    'id_anggota' => $anggota->id_anggota,
                    'jenis_simpanan' => 'Pokok',
                    'jumlah' => 50000.00,
                    'tanggal' => now()->toDateString(),
                ]);
            }

            return $anggota->load(['account', 'cabang']);
        });

        $service = app(UserDirectoryService::class);
        $mapped = $service->mapAccountToMember($result->account);

        return $this->successResponse('Anggota berhasil disetujui.', $mapped);
    }

    /**
     * Menolak pendaftaran anggota baru (status Tertunda -> Ditolak)
     */
    public function reject(int $id): JsonResponse
    {
        $anggota = Anggota::where('id_account', $id)->orWhere('id_anggota', $id)->first();

        if (! $anggota) {
            return $this->errorResponse('Anggota tidak ditemukan.', null, 404);
        }

        if ($anggota->status !== 'Tertunda' && $anggota->status !== 'Calon') {
            return $this->errorResponse(
                'Hanya anggota dengan status Tertunda yang bisa ditolak.',
                null,
                422
            );
        }

        $anggota->status = 'Ditolak';
        $anggota->save();

        $service = app(UserDirectoryService::class);
        $mapped = $service->mapAccountToMember($anggota->account);

        return $this->successResponse('Pendaftaran anggota berhasil ditolak.', $mapped);
    }

    /**
     * Menghapus massal data anggota/pengguna berdasarkan array ID.
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|integer',
        ]);

        $ids = $validated['ids'];
        $deletedCount = 0;

        DB::transaction(function () use ($ids, &$deletedCount) {
            $service = app(UserDirectoryService::class);
            foreach ($ids as $id) {
                $account = Account::find($id);
                if (!$account) {
                    $anggota = Anggota::find($id);
                    if ($anggota) {
                        $account = Account::find($anggota->id_account);
                    }
                }

                if ($account) {
                    $service->deleteMember($account->id_account);
                    $deletedCount++;
                }
            }
        });

        return $this->successResponse("Berhasil menghapus {$deletedCount} pengguna.", null);
    }

    /**
     * Aktivasi calon anggota (Admin only, legacy fallback).
     */
    public function activate(ActivateAnggotaRequest $request, int $id_anggota): JsonResponse
    {
        return $this->approve($id_anggota);
    }
}
