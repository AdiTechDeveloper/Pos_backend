<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\GstRateController;
use App\Http\Controllers\Api\ManagerBranchController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseBillController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\SupplierController;
use Illuminate\Support\Facades\Route;

// login route
Route::post('/login', [AuthController::class, 'login']);

// store registration route
Route::post('/stores', [StoreController::class, 'store']);

// stores routes for superadmin users
Route::middleware(['auth:sanctum', 'token.expiry', 'check.superadmin'])->group(function () {
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

// manager routes
Route::middleware(['auth:sanctum', 'token.expiry', 'api.auth.response'])->group(function () {
    // Manager logged-in branches
    Route::get('/manager/branches', [ManagerBranchController::class, 'myBranches']);
    // Branch-wise staff
    Route::get('/manager/branches/{branchId}/staff', [ManagerBranchController::class, 'branchStaff']);
    // Staff detail
    Route::get('/manager/staff/{staffId}', [ManagerBranchController::class, 'staffDetail']);
});

// category routes
Route::middleware(['auth:sanctum', 'token.expiry', 'api.auth.response'])->group(function () {
    Route::get('/categories/parents', [CategoryController::class, 'parentCategories']);
    Route::get('/categories/{id}/subcategories', [CategoryController::class, 'subCategories']);
    Route::get('/categories/tree', [CategoryController::class, 'categoryTree']);

    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{id}', [CategoryController::class, 'show']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
});

// brand routes
Route::middleware(['auth:sanctum', 'token.expiry', 'api.auth.response'])->group(function () {
    Route::get('/brands', [BrandController::class, 'index']);
    Route::get('/brands/{id}', [BrandController::class, 'show']);
    Route::post('/brands', [BrandController::class, 'store']);
    Route::put('/brands/{id}', [BrandController::class, 'update']);
    Route::delete('/brands/{id}', [BrandController::class, 'destroy']);
});

// supplier routes
Route::middleware(['auth:sanctum', 'token.expiry', 'api.auth.response'])->group(function () {
    Route::get('/suppliers', [SupplierController::class, 'index']);
    Route::get('/suppliers/{id}', [SupplierController::class, 'show']);
    Route::post('/suppliers', [SupplierController::class, 'store']);
    Route::put('/suppliers/{id}', [SupplierController::class, 'update']);
    Route::delete('/suppliers/{id}', [SupplierController::class, 'destroy']);
});

// GST Rate routes
Route::middleware(['auth:sanctum', 'token.expiry', 'api.auth.response'])->group(function () {
    Route::get('/gst-rates', [GstRateController::class, 'index']);
    Route::get('/gst-rates/{id}', [GstRateController::class, 'show']);
    Route::post('/gst-rates', [GstRateController::class, 'store']);
    Route::put('/gst-rates/{id}', [GstRateController::class, 'update']);
    Route::delete('/gst-rates/{id}', [GstRateController::class, 'destroy']);
    Route::patch('/gst-rates/{id}/toggle-status', [GstRateController::class, 'toggleActive']);
});

// Product routes
Route::middleware(['auth:sanctum', 'token.expiry', 'api.auth.response'])->group(function () {
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{id}', [ProductController::class, 'show']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);
    Route::get('/products/{id}/barcode', [ProductController::class, 'barcodeImage']);
});

// Purchasebill routes
Route::middleware(['auth:sanctum', 'token.expiry', 'api.auth.response'])->group(function () {
    Route::get('/purchase-bill', [PurchaseBillController::class, 'index']);
    Route::get('/purchase-bill/{id}', [PurchaseBillController::class, 'show']);
    Route::post('/purchase-bill', [PurchaseBillController::class, 'store']);
});
