<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

// Route::middleware(['auth:sanctum', 'token.expiry'])->group(function () {
//     Route::post('/stores', [StoreController::class, 'store']);
//     Route::get('/stores', [StoreController::class, 'index']);
// });
