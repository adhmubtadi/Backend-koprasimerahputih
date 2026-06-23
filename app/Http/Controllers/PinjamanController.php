<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApprovePinjamanRequest;
use App\Http\Requests\StorePinjamanRequest;
use App\Http\Resources\PinjamanResource;
use App\Models\Anggota;
use App\Models\Pinjaman;
use App\Services\PinjamanService;
use App\Traits\ApiResponse;
use App\Traits\ResolvesCabangScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PinjamanController extends Controller
{
    use ApiResponse, ResolvesCabangScope;

    public function __construct(private readonly PinjamanService $pinjamanService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Pinjaman::with(['anggota.cabang', 'pengurusAcc']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $cabangScope = $this->resolveCabangScope($request);
        if ($cabangScope !== null) {
            $query->whereHas('anggota', fn ($q) => $q->where('id_cabang', $cabangScope));
        }

        if ($request->user()?->role === 'Anggota') {
            $query->where('id_anggota', $request->user()->anggota->id_anggota);
        }

        $data = $query->orderByDesc('tanggal_pengajuan')->get();

        return $this->successResponse(
            'Daftar pinjaman berhasil diambil.',
            PinjamanResource::collection($data)
        );
    }

    public function store(StorePinjamanRequest $request): JsonResponse
    {
        try {
            $pinjaman = $this->pinjamanService->ajukanPinjaman($request->validated());

            return $this->successResponse(
                'Pengajuan pinjaman berhasil dibuat. Menunggu persetujuan pengurus.',
                new PinjamanResource($pinjaman),
                201
            );
        } catch (\Throwable $e) {
            return $this->errorResponse('Terjadi kesalahan saat membuat pinjaman.', $e->getMessage(), 422);
        }
    }

    public function approve(ApprovePinjamanRequest $request, int $id_pinjaman): JsonResponse
    {
        $validated = $request->validated();
        $id_pinjaman = (int) $validated['id_pinjaman'];

        DB::beginTransaction();
        try {
            $pinjaman = Pinjaman::lockForUpdate()->with('anggota')->find($id_pinjaman);

            if (! $pinjaman) {
                DB::rollBack();
                return $this->errorResponse('Pinjaman tidak ditemukan.', null, 404);
            }

            if ($pinjaman->status !== 'Pending') {
                DB::rollBack();
                return $this->errorResponse(
                    'Pinjaman hanya bisa di-ACC dari status Pending.',
                    new PinjamanResource($pinjaman),
                    422
                );
            }

            $pengurus = $request->user()?->pengurus;
            if (! $pengurus) {
                DB::rollBack();
                return $this->errorResponse('Akun pengurus tidak valid.', null, 422);
            }

            $cabangScope = $this->resolveCabangScope($request);
            if ($cabangScope !== null && $pinjaman->anggota?->id_cabang !== $cabangScope) {
                DB::rollBack();
                return $this->errorResponse('Pinjaman ini bukan dari cabang Anda.', null, 403);
            }

            $pinjaman->status = 'Approved';
            $pinjaman->id_pengurus_acc = $pengurus->id_pengurus;
            $pinjaman->save();

            app(\App\Services\JurnalService::class)->catatPinjamanDisetujui($pinjaman);

            DB::commit();

            return $this->successResponse(
                'Pinjaman berhasil di-ACC.',
                new PinjamanResource($pinjaman->fresh(['anggota', 'pengurusAcc'])),
                200
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->errorResponse('Terjadi kesalahan saat ACC pinjaman.', $e->getMessage(), 500);
        }
    }

    public function reject(ApprovePinjamanRequest $request, int $id_pinjaman): JsonResponse
    {
        $validated = $request->validated();
        $id_pinjaman = (int) $validated['id_pinjaman'];

        DB::beginTransaction();
        try {
            $pinjaman = Pinjaman::lockForUpdate()->with('anggota')->find($id_pinjaman);

            if (! $pinjaman) {
                DB::rollBack();
                return $this->errorResponse('Pinjaman tidak ditemukan.', null, 404);
            }

            if ($pinjaman->status !== 'Pending') {
                DB::rollBack();
                return $this->errorResponse(
                    'Pinjaman hanya bisa ditolak dari status Pending.',
                    new PinjamanResource($pinjaman),
                    422
                );
            }

            $cabangScope = $this->resolveCabangScope($request);
            if ($cabangScope !== null && $pinjaman->anggota?->id_cabang !== $cabangScope) {
                DB::rollBack();
                return $this->errorResponse('Pinjaman ini bukan dari cabang Anda.', null, 403);
            }

            $pinjaman->status = 'Rejected';
            $pinjaman->save();

            DB::commit();

            return $this->successResponse(
                'Pinjaman berhasil ditolak.',
                new PinjamanResource($pinjaman->fresh(['anggota', 'pengurusAcc'])),
                200
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->errorResponse('Terjadi kesalahan saat menolak pinjaman.', $e->getMessage(), 500);
        }
    }

    public function showStatus(int $id_pinjaman): JsonResponse
    {
        $pinjaman = Pinjaman::with(['anggota', 'pengurusAcc'])->find($id_pinjaman);

        if (! $pinjaman) {
            return $this->errorResponse('Data pinjaman tidak ditemukan.', null, 404);
        }

        $user = request()->user();
        if ($user?->role === 'Anggota') {
            $anggota = $user->anggota;
            if (! $anggota || $pinjaman->id_anggota !== $anggota->id_anggota) {
                return $this->errorResponse('Anda tidak punya akses ke pinjaman ini.', null, 403);
            }
        }

        return $this->successResponse(
            'Status pinjaman berhasil diambil.',
            new PinjamanResource($pinjaman),
            200
        );
    }

    public function destroy(int $id_pinjaman): JsonResponse
    {
        $pinjaman = Pinjaman::find($id_pinjaman);

        if (! $pinjaman) {
            return $this->errorResponse('Data pinjaman tidak ditemukan.', null, 404);
        }

        $pinjaman->delete();

        return $this->successResponse('Pinjaman berhasil dihapus.', null, 200);
    }
}
