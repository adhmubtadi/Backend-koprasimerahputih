<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminMemberController;
use App\Http\Controllers\PinjamanController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UsulanStokController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\AngsuranController;
use App\Http\Controllers\AnggotaController;
use App\Http\Controllers\SimpananController;
use App\Http\Controllers\ProdukController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\CabangController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\PortalAnggotaController;
use App\Http\Controllers\GoogleAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// --- PUBLIC ---
Route::post('/login', [AuthController::class, 'login']);
Route::post('/anggota/register', [AnggotaController::class, 'register']);
Route::get('/cabangs', [CabangController::class, 'index']);
Route::get('/auth/google', [GoogleAuthController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [GoogleAuthController::class, 'handleGoogleCallback']);

// --- PROTECTED ---
Route::middleware('auth:sanctum')->group(function () {

    Route::get('/me', function (Request $request) {
        return response()->json($request->user()->load([
            'admin', 'pengurus.cabang', 'kasir.cabang', 'gudang.cabang', 'anggota.cabang',
        ]));
    });

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/simpanans', [SimpananController::class, 'store'])
        ->middleware('role:Anggota,Pengurus,Admin');

    // Dashboard monitoring (Pengurus & Admin)
    Route::middleware('role:Pengurus,Admin')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::patch('/members/{id}/approve', [AnggotaController::class, 'approve']);
        Route::patch('/members/{id}/reject', [AnggotaController::class, 'reject']);
        Route::post('/members/bulk-delete', [AnggotaController::class, 'bulkDelete']);
    });

    // --- ADMIN ONLY: Manajemen Anggota & Pengguna ---
    Route::middleware('role:Admin')->group(function () {
        Route::get('/admin/members', [AdminMemberController::class, 'index']);
        Route::post('/admin/members', [AdminMemberController::class, 'store']);
        Route::put('/admin/members/{id_account}', [AdminMemberController::class, 'update']);
        Route::patch('/admin/members/{id_account}', [AdminMemberController::class, 'update']);
        Route::delete('/admin/members/{id_account}', [AdminMemberController::class, 'destroy']);

        Route::patch('/anggota/{id_anggota}/activate', [AnggotaController::class, 'activate']);
        Route::delete('/pinjamans/{id_pinjaman}', [PinjamanController::class, 'destroy']);

        Route::get('/users', [UserManagementController::class, 'index']);
        Route::post('/users', [UserManagementController::class, 'store']);
    });

    // --- PORTAL ANGGOTA ---
    Route::middleware('role:Anggota')->group(function () {
        Route::get('/portal', [PortalAnggotaController::class, 'dashboard']);
        Route::get('/portal/transaksi', [PortalAnggotaController::class, 'riwayatTransaksi']);
        Route::get('/portal/me', [AnggotaController::class, 'me']);
        Route::get('/simpanans', [SimpananController::class, 'index']);
        Route::get('/simpanans/rules', [SimpananController::class, 'rules']);
        Route::post('/pinjamans', [PinjamanController::class, 'store']);
        Route::get('/pinjamans', [PinjamanController::class, 'index']);
        Route::get('/pinjamans/{id_pinjaman}/status', [PinjamanController::class, 'showStatus']);
        Route::post('/angsurans', [AngsuranController::class, 'store']);
        Route::get('/my-history', [AngsuranController::class, 'history']);
        Route::get('/my-loan-remaining/{id_pinjaman}', [AngsuranController::class, 'checkSisa']);
    });

    // --- KASIR: POS & struk (2 kasir per cabang) ---
    Route::middleware('role:Kasir')->group(function () {
        Route::post('/checkout', [TransactionController::class, 'checkout']);
        Route::get('/transactions/{id_transaksi}/receipt', [TransactionController::class, 'receipt']);
        Route::get('/produks/list', [ProdukController::class, 'index']);
    });

    // --- GUDANG, PENGURUS, ADMIN: produk management ---
    Route::middleware('role:Gudang,Pengurus,Admin')->group(function () {
        Route::apiResource('produks', ProdukController::class)->except(['index']);
    });

    // --- GUDANG: stok gudang & usulan pembelian ---
    Route::middleware('role:Gudang,Admin')->group(function () {
        Route::apiResource('suppliers', SupplierController::class);
        Route::post('/usulan-stoks', [UsulanStokController::class, 'store']);
        Route::get('/usulan-stoks', [UsulanStokController::class, 'index']);
        Route::get('/produks/stok', [ProdukController::class, 'index']);
    });

    // --- PENGURUS: simpan pinjam, approval, laporan, monitoring cabang ---
    Route::middleware('role:Pengurus,Admin')->group(function () {
        // Manajemen anggota (cabang pengurus / global admin)
        Route::get('/anggota', [AnggotaController::class, 'index']);
        Route::post('/anggota', [AnggotaController::class, 'store']);
        Route::get('/anggota/{id_anggota}', [AnggotaController::class, 'show']);
        Route::put('/anggota/{id_anggota}', [AnggotaController::class, 'update']);
        Route::patch('/anggota/{id_anggota}', [AnggotaController::class, 'update']);
        Route::delete('/anggota/{id_anggota}', [AnggotaController::class, 'destroy']);

        // Simpan pinjam
        Route::get('/pinjamans/manage', [PinjamanController::class, 'index']);
        Route::patch('/pinjamans/{id_pinjaman}/approve', [PinjamanController::class, 'approve']);
        Route::patch('/pinjamans/{id_pinjaman}/reject', [PinjamanController::class, 'reject']);
        Route::get('/pinjamans/{id_pinjaman}/status', [PinjamanController::class, 'showStatus']);
        Route::get('/angsurans/manage', [AngsuranController::class, 'history']);
        Route::patch('/angsurans/{id_angsuran}/verify', [AngsuranController::class, 'verify']);
        Route::patch('/angsurans/{id_angsuran}/reject', [AngsuranController::class, 'reject']);
        Route::get('/simpanans/manage', [SimpananController::class, 'index']);
        Route::patch('/simpanans/{id_simpanan}/verify', [SimpananController::class, 'verify']);
        Route::patch('/simpanans/{id_simpanan}/reject', [SimpananController::class, 'reject']);
        Route::get('/simpanans/saldo/{id_anggota}', [SimpananController::class, 'cekSaldo']);

        // Persetujuan usulan stok & monitoring stok
        Route::patch('/usulan-stoks/{id_usulan}/approve', [UsulanStokController::class, 'approveUsulan']);
        Route::get('/usulan-stoks/manage', [UsulanStokController::class, 'index']);
        Route::get('/produks/monitor', [ProdukController::class, 'index']);

        // Laporan keuangan & operasional
        Route::prefix('reports')->group(function () {
            Route::get('/anggota', [ReportController::class, 'anggota']);
            Route::get('/simpanan', [ReportController::class, 'simpanan']);
            Route::get('/pinjaman', [ReportController::class, 'pinjaman']);
            Route::get('/sales', [ReportController::class, 'sales']);
            Route::get('/persediaan', [ReportController::class, 'persediaan']);
            Route::get('/jurnal-umum', [ReportController::class, 'jurnalUmum']);
            Route::get('/buku-besar', [ReportController::class, 'bukuBesar']);
            Route::get('/neraca', [ReportController::class, 'neraca']);
            Route::get('/laba-rugi', [ReportController::class, 'labaRugi']);
            Route::get('/shu', [ReportController::class, 'shu']);
            Route::get('/shu-final', [ReportController::class, 'shuFinal']);
        });
    });
});
