<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Anggota;
use App\Models\Cabang;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    /**
     * Redirect the user to the Google authentication page.
     */
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    /**
     * Obtain the user information from Google and generate Sanctum token.
     */
    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
            $email = $googleUser->getEmail();
            $name = $googleUser->getName();

            // Find member by email
            $anggota = Anggota::where('email', $email)->first();

            if ($anggota) {
                $account = $anggota->account;
            } else {
                // If not registered, create a new Account + Anggota profile
                $username = explode('@', $email)[0] . '_' . rand(100, 999);
                
                $account = Account::create([
                    'username' => $username,
                    'password' => Hash::make(Str::random(16)),
                    'role' => 'Anggota',
                    'email' => $email,
                ]);

                // Assign to the first branch as default
                $cabang = Cabang::first();
                $idCabang = $cabang ? $cabang->id_cabang : 1;

                $anggota = Anggota::create([
                    'id_account' => $account->id_account,
                    'nama_anggota' => $name,
                    'alamat' => 'Pendaftaran via Google',
                    'no_hp' => '-',
                    'email' => $email,
                    'id_cabang' => $idCabang,
                    'tanggal_daftar' => now()->toDateString(),
                    'status' => 'Tertunda',
                ]);
            }

            // Generate Laravel Sanctum Token
            $token = $account->createToken('auth_token')->plainTextToken;

            // Redirect back to frontend login page with the token
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            return redirect()->away($frontendUrl . '/login?token=' . $token);

        } catch (\Exception $e) {
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            return redirect()->away($frontendUrl . '/login?error=' . urlencode('Gagal login via Google: ' . $e->getMessage()));
        }
    }
}
