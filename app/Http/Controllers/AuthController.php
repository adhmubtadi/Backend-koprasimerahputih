<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\Controller;

class AuthController extends Controller
{
    use ApiResponse;

    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        // Cari user berdasarkan username atau email
        $loginInput = $request->username;
        $user = Account::where('username', $loginInput)
            ->orWhere('email', $loginInput)
            ->first();

        if (!$user && filter_var($loginInput, FILTER_VALIDATE_EMAIL)) {
            $anggota = \App\Models\Anggota::where('email', $loginInput)->first();
            if ($anggota) {
                $user = Account::find($anggota->id_account);
            }
        }

        // Cek apakah user ada dan passwordnya benar
        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->errorResponse('Kredensial tidak valid', null, 401);
        }

        // Buat token Sanctum
        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->successResponse('Login berhasil', [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id_account' => $user->id_account,
                'username' => $user->username,
                'role' => $user->role,
            ]
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return $this->successResponse('Logout berhasil', null);
    }
}