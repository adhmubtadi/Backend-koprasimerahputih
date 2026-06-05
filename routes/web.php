<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Fallback login tanpa prefix /api (untuk Postman/frontend yang hit /login langsung)
Route::post('/login', [AuthController::class, 'login']);
