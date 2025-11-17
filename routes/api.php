<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\StaffController;
use Illuminate\Support\Facades\Route;

// login route
Route::post('/login', [AuthController::class, 'login']);

// stores routes
Route::middleware(['auth:sanctum', 'token.expiry', 'check.superadmin'])->group(function () {
    Route::post('/stores', [StoreController::class, 'store']);
    Route::get('/stores', [StoreController::class, 'index']);
    Route::get('/stores/{id}', [StoreController::class, 'show']);
    Route::put('/stores/{id}', [StoreController::class, 'update']);
    Route::delete('/stores/{id}', [StoreController::class, 'destroy']);
});

// specific store routes for admin users
Route::middleware(['auth:sanctum', 'token.expiry'])->group(function () {
    Route::get('/stores/{id}', [StoreController::class, 'show']);
    Route::put('/stores/{id}', [StoreController::class, 'update']);
});

// branches routes
Route::middleware(['auth:sanctum', 'token.expiry', 'api.auth.response', 'check.admin'])->group(function () {
    Route::post('/branches', [BranchController::class, 'store']);
    Route::get('/branches', [BranchController::class, 'index']);
    Route::get('/branches/{id}', [BranchController::class, 'show']);
    Route::put('/branches/{id}', [BranchController::class, 'update']);
    Route::delete('/branches/{id}', [BranchController::class, 'destroy']);
});

// staff routes
Route::middleware(['auth:sanctum', 'token.expiry', 'api.auth.response', 'check.admin'])->group(function () {
    Route::post('/staff', [StaffController::class, 'store']);
    Route::get('/staff', [StaffController::class, 'index']);
    Route::get('/staff/{id}', [StaffController::class, 'show']);
    Route::put('/staff/{id}', [StaffController::class, 'update']);
    Route::delete('/staff/{id}', [StaffController::class, 'destroy']);
    Route::patch('/staff/{id}/toggle-status', [StaffController::class, 'toggleActive']);
});
