<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\FinancialReportController;
use App\Http\Controllers\Api\GSTOutputReportController;
use App\Http\Controllers\Api\GstRateController;
use App\Http\Controllers\Api\ManagerBranchController;
use App\Http\Controllers\Api\PriceOverrideController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseBillController;
use App\Http\Controllers\Api\PurchaseLineController;
use App\Http\Controllers\Api\PurchaseReportController;
use App\Http\Controllers\Api\PurchaseReturnController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SalesBillController;
use App\Http\Controllers\Api\SalesReportController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\StockAlertController;
use App\Http\Controllers\Api\StockExpiryController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\SupplierController;
use Illuminate\Support\Facades\Route;

// Put this at the very top of api.php, outside any groups
Route::get('/staff/register-status', [App\Http\Controllers\Api\StaffController::class, 'getRegisterStatus'])
     ->middleware('auth:sanctum');
     Route::get('/staff/shift-summary', [StaffController::class, 'getShiftSummary'])->middleware('auth:sanctum');
Route::post('/staff/close-register', [StaffController::class, 'closeRegister'])->middleware('auth:sanctum');
Route::post('/open-register',  [StaffController::class, 'openRegister'])->middleware('auth:sanctum');

// login route
Route::post('/login', [AuthController::class, 'login']);

// store registration route
Route::post('/stores', [StoreController::class, 'store']);
Route::get('/stores/{id}', [StoreController::class, 'show']);

Route::middleware(['auth:sanctum', 'token.expiry'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
});

// stores routes for superadmin users
Route::middleware(['auth:sanctum', 'token.expiry', 'check.superadmin'])->group(function () {
    Route::get('/stores', [StoreController::class, 'index']);
    Route::put('/stores/{id}', [StoreController::class, 'update']);
    Route::post('/stores/{id}', [StoreController::class, 'update']);
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


// --- TEST GROUP ---
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/staff/register-status', [StaffController::class, 'getRegisterStatus']);
    Route::post('/staff/open-register', [StaffController::class, 'openRegister']);
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
    Route::put('/purchase-bill/{id}', [PurchaseBillController::class, 'update']);
    Route::get('/purchase-line', [PurchaseLineController::class, 'index']);
    Route::delete('purchase-bill/{id}', [PurchaseBillController::class, 'destroy']);
});

// Purchasebill return routes
Route::middleware(['auth:sanctum', 'token.expiry', 'api.auth.response'])->group(function () {
    Route::get('/purchase-return', [PurchaseReturnController::class, 'index']);
    Route::get('/purchase-return/{id}', [PurchaseReturnController::class, 'show']);

    // purchase replacement
    Route::post('/purchase-replacement', [PurchaseReturnController::class, 'purchaseReplacement']);
    Route::post('/purchase-return', [PurchaseReturnController::class, 'purchaseReturn']);
});

// Salesbill routes
Route::middleware(['auth:sanctum', 'token.expiry', 'api.auth.response'])->group(function () {
    Route::get('/sales-bills', [SalesBillController::class, 'index']);
    Route::get('/sales-bill/{id}', [SalesBillController::class, 'show']);
    // Product scan (used by POS)
    Route::post('/sales/scan', [SalesBillController::class, 'scanBarcode']);
    Route::post('/sales-bills', [SalesBillController::class, 'store']);
    // gst report
    Route::get('/sales-bills/gst-report', [SalesBillController::class, 'gstReport']);
    // Payment method
    Route::post('/sales-bills/pay', [SalesBillController::class, 'pay']);
    Route::post('/sales-bills/collect-payment', [SalesBillController::class, 'collectPayment']);
    Route::get('/customer/due', [SalesBillController::class, 'customerWithDue']);
    Route::get('/customer-due/{customerMobile}', [SalesBillController::class, 'customerDue']);
    Route::post('/sales-bill/print-data', [SalesBillController::class, 'getPrintData']);

    Route::post('/sales-bills/customer-pay-due', [SalesBillController::class, 'customerPayDue']);
});

// Report routes
Route::middleware(['auth:sanctum', 'token.expiry', 'api.auth.response'])->group(function () {
    Route::get('/reports/profit-loss', [ReportController::class, 'profitLoss']);
    Route::get('/reports/top-selling-products', [ReportController::class, 'topSellingProducts']);

    // admin reports
    Route::get('/reports/stock-summary', [ReportController::class, 'stockSummary']);
    Route::post('/reports/purchase', [ReportController::class, 'purchaseSummary']);
    Route::post('/reports/sales-summary', [ReportController::class, 'salesSummary']);
    Route::post('/reports/sales-analytics', [ReportController::class, 'salesAnalytics']);
    Route::get('price-override-report', [PriceOverrideController::class, 'index']);

    // GST reports
    Route::post('/reports/gst/sales-GST', [GSTOutputReportController::class, 'gstOutputReport']);
    Route::post('/reports/gst/gstr3b-Summary', [GSTOutputReportController::class, 'gstr3bSummary']);
    Route::post('/reports/gst/gstr1-Summary', [GSTOutputReportController::class, 'gstr1Summary']);

    Route::get('/reports/sales-report', [SalesReportController::class, 'index']);
    Route::get('/reports/purchase-report', [PurchaseReportController::class, 'index']);
    Route::get('/reports/financial-report', [FinancialReportController::class, 'index']);
});

// admin reports
Route::middleware(['auth:sanctum', 'token.expiry', 'api.auth.response', 'check.admin'])->group(function () {});

// Stock Expiry Alerts routes
Route::middleware(['auth:sanctum', 'token.expiry', 'api.auth.response'])->group(function () {
    Route::get('/stock-expiry-alerts', [StockExpiryController::class, 'stockExpiryAlerts']);
});

Route::middleware(['auth:sanctum', 'token.expiry', 'api.auth.response'])->group(function () {
    Route::get('/stock-alerts', [StockAlertController::class, 'index']);
});

 Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/staff/register-status', [StaffController::class, 'getRegisterStatus']);
    Route::post('/staff/open-register', [StaffController::class, 'openRegister']);
});