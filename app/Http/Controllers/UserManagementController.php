<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStaffUserRequest;
use App\Services\UserDirectoryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserManagementController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly UserDirectoryService $userDirectory)
    {
    }

    /**
     * Admin membuat user staff sesuai modul operasional harian.
     */
    public function store(StoreStaffUserRequest $request): JsonResponse
    {
        try {
            $result = $this->userDirectory->createStaffUser($request->validated());

            return $this->successResponse('User staff berhasil dibuat.', [
                'account' => [
                    'id_account' => $result['account']->id_account,
                    'username' => $result['account']->username,
                    'role' => $result['account']->role,
                ],
                'profile' => $result['profile'],
            ], 201);
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal membuat user staff.', $e->getMessage(), 500);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $role = $request->query('role');
        $idCabang = $request->filled('id_cabang') ? (int) $request->query('id_cabang') : null;

        $data = $this->userDirectory->listStaff(
            is_string($role) ? $role : null,
            $idCabang
        );

        return $this->successResponse('Daftar user berhasil diambil.', $data);
    }
}
