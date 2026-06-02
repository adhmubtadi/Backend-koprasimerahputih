<?php

use App\Http\Controllers\AuthController;
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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// 1. ROUTE PUBLIC
Route::post('/login', [AuthController::class, 'login']);
Route::post('/anggota/register', [AnggotaController::class, 'register']);
Route::get('/cabangs', [CabangController::class, 'index']);

// 2. ROUTE PROTECTED
Route::middleware('auth:sanctum')->group(function () {
    
    Route::get('/me', function (Request $request) {
        return response()->json($request->user());
    });

    // Dashboard (Akses Semua Role)
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // --- FITUR ANGGOTA (SELF-SERVICE) ---
    Route::middleware('role:Anggota')->group(function () {
        Route::get('/anggota/me', [AnggotaController::class, 'me']);
        Route::post('/angsurans', [AngsuranController::class, 'store']); // Ajukan Bayar
        Route::post('/pinjamans', [PinjamanController::class, 'store']); // Ajukan Pinjam
        Route::get('/my-history', [AngsuranController::class, 'history']); 
        Route::get('/my-loan-remaining/{id_pinjaman}', [AngsuranController::class, 'checkSisa']);
    });

    // --- MODUL KASIR (POS & SIMPANAN) ---
    Route::middleware('role:Kasir,Admin')->group(function () {
        Route::post('/checkout', [TransactionController::class, 'checkout']);
        Route::get('/transactions/{id_transaksi}/receipt', [TransactionController::class, 'receipt']);
        Route::get('/simpanans', [SimpananController::class, 'index']);
        Route::post('/simpanans', [SimpananController::class, 'store']);
        Route::get('/produks/list', [ProdukController::class, 'index']); // Kasir butuh liat stok buat jualan
    });

    // --- MODUL LOGISTIK (GUDANG) ---
    Route::middleware('role:Gudang,Admin')->group(function () {
        Route::apiResource('produks', ProdukController::class)->except(['index']); 
        Route::apiResource('suppliers', SupplierController::class);
        Route::post('/usulan-stoks', [UsulanStokController::class, 'store']);
        Route::get('/usulan-stoks', [UsulanStokController::class, 'index']);
    });

    // --- MODUL APPROVAL (PENGURUS & ADMIN) ---
    Route::middleware('role:Pengurus,Admin')->group(function () {
        Route::get('/anggota', [AnggotaController::class, 'index']);
        Route::post('/anggota', [AnggotaController::class, 'store']);
        Route::get('/anggota/{id_anggota}', [AnggotaController::class, 'show']);
        Route::put('/anggota/{id_anggota}', [AnggotaController::class, 'update']);
        Route::patch('/anggota/{id_anggota}', [AnggotaController::class, 'update']);
        Route::delete('/anggota/{id_anggota}', [AnggotaController::class, 'destroy']);
        Route::patch('/angsurans/{id_angsuran}/verify', [AngsuranController::class, 'verify']);
        Route::patch('/pinjamans/{id_pinjaman}/approve', [PinjamanController::class, 'approve']);
        Route::patch('/usulan-stoks/{id_usulan}/approve', [UsulanStokController::class, 'approveUsulan']);
        Route::get('/reports/sales', [ReportController::class, 'sales']);
        Route::get('/reports/shu', [ReportController::class, 'shu']);
        Route::get('/reports/jurnal-umum', [ReportController::class, 'jurnalUmum']);
        Route::get('/reports/buku-besar', [ReportController::class, 'bukuBesar']);
        Route::get('/reports/neraca', [ReportController::class, 'neraca']);
        Route::get('/reports/laba-rugi', [ReportController::class, 'labaRugi']);
        Route::get('/reports/shu-final', [ReportController::class, 'shuFinal']);
        Route::get('/reports/anggota', [ReportController::class, 'anggota']);
        Route::get('/reports/simpanan', [ReportController::class, 'simpanan']);
        Route::get('/reports/persediaan', [ReportController::class, 'persediaan']);
        
        // Cek Detail (Global Access for Management)
        Route::get('/pinjamans/{id_pinjaman}/status', [PinjamanController::class, 'showStatus']);
        Route::get('/simpanans/saldo/{id_anggota}', [SimpananController::class, 'cekSaldo']);
    });

    // --- SUPER ADMIN ONLY ---
    Route::middleware('role:Admin')->group(function () {
        Route::patch('/anggota/{id_anggota}/activate', [AnggotaController::class, 'activate']);
        Route::delete('/pinjamans/{id_pinjaman}', [PinjamanController::class, 'destroy']);
        
    });

    Route::post('/logout', [AuthController::class, 'logout']);
});