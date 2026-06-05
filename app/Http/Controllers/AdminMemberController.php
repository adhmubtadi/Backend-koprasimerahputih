<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Http\Resources\MemberDirectoryResource;
use App\Services\UserDirectoryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Halaman Manajemen Anggota — hanya Admin.
 * Menampilkan semua pengguna sistem (Admin, Pengurus, Kasir, Gudang, Anggota).
 */
class AdminMemberController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly UserDirectoryService $userDirectory)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->userDirectory->listMembersForAdmin([
            'q' => $request->query('q'),
            'role' => $request->query('role'),
            'status' => $request->query('status'),
            'page' => $request->query('page', 1),
        ], (int) $request->query('per_page', 9));

        return $this->successResponse('Daftar pengguna berhasil diambil.', [
            'filters' => [
                'q' => $request->query('q'),
                'role' => $request->query('role'),
                'status' => $request->query('status'),
            ],
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'data' => MemberDirectoryResource::collection(collect($paginator->items())),
        ]);
    }

    public function store(StoreMemberRequest $request): JsonResponse
    {
        try {
            $result = $this->userDirectory->createMember($request->validated());
            $member = $this->userDirectory->mapAccountToMember(
                $result['account']->load([
                    'admin', 'pengurus.cabang', 'kasir.cabang', 'gudang.cabang', 'anggota.cabang',
                ])
            );

            return $this->successResponse(
                'Pengguna berhasil ditambahkan.',
                new MemberDirectoryResource($member),
                201
            );
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal menambah pengguna.', $e->getMessage(), 422);
        }
    }

    public function update(UpdateMemberRequest $request, int $id_account): JsonResponse
    {
        try {
            $result = $this->userDirectory->updateMember($id_account, $request->validated());

            return $this->successResponse(
                'Data pengguna berhasil diperbarui.',
                new MemberDirectoryResource($result['member'])
            );
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal memperbarui pengguna.', $e->getMessage(), 422);
        }
    }

    public function destroy(int $id_account): JsonResponse
    {
        try {
            $this->userDirectory->deleteMember($id_account);

            return $this->successResponse('Pengguna berhasil dihapus.', null);
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal menghapus pengguna.', $e->getMessage(), 422);
        }
    }
}
