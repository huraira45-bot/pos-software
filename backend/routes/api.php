<?php

use App\Http\Controllers\Api\AtlImportController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ComplianceController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\HeldCartController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReturnController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\TerminalController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/config/public', [ConfigController::class, 'public']);

    Route::get('/sales', [SaleController::class, 'index']);
    Route::post('/sales', [SaleController::class, 'store']);
    Route::get('/sales/{invoice}', [SaleController::class, 'show']);
    Route::get('/sales/{invoice}/receipt', [ReceiptController::class, 'show']);
    Route::get('/sales/{invoice}/receipt.pdf', [ReceiptController::class, 'pdf']);

    Route::get('/returns/lookup', [ReturnController::class, 'lookup']);
    Route::post('/returns', [ReturnController::class, 'store']);

    Route::get('/held-carts', [HeldCartController::class, 'index']);
    Route::post('/held-carts', [HeldCartController::class, 'store']);
    Route::post('/held-carts/{heldCart}/recall', [HeldCartController::class, 'recall']);
    Route::delete('/held-carts/{heldCart}', [HeldCartController::class, 'destroy']);

    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::put('/products/{product}', [ProductController::class, 'update']);

    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);

    Route::get('/suppliers', [SupplierController::class, 'index']);
    Route::post('/suppliers', [SupplierController::class, 'store']);
    Route::put('/suppliers/{supplier}', [SupplierController::class, 'update']);

    Route::get('/purchases', [PurchaseController::class, 'index']);
    Route::post('/purchases', [PurchaseController::class, 'store']);
    Route::get('/purchases/{purchase}', [PurchaseController::class, 'show']);
    Route::post('/purchases/{purchase}/receive', [PurchaseController::class, 'receive']);

    Route::get('/stock-levels', [StockController::class, 'levels']);
    Route::get('/stock-levels/low-stock', [StockController::class, 'lowStock']);
    Route::post('/stock-adjustments', [StockController::class, 'adjust']);

    Route::get('/reports/day-close', [ReportController::class, 'dayClose']);
    Route::get('/reports/sales-by-item', [ReportController::class, 'salesByItem']);
    Route::get('/reports/sales-by-category', [ReportController::class, 'salesByCategory']);
    Route::get('/reports/sales-by-cashier', [ReportController::class, 'salesByCashier']);
    Route::get('/reports/tax-collected', [ReportController::class, 'taxCollected']);
    Route::get('/reports/reconciliation', [ReportController::class, 'reconciliation']);
    Route::get('/reports/inventory-valuation', [ReportController::class, 'inventoryValuation']);
    Route::get('/reports/sales-by-customer', [ReportController::class, 'salesByCustomer']);
    Route::get('/reports/b2b-invoices', [ReportController::class, 'b2bInvoices']);

    Route::get('/compliance/sync-health', [ComplianceController::class, 'syncHealth']);
    Route::get('/compliance/failed', [ComplianceController::class, 'failed']);
    Route::post('/compliance/retry/{fiscalOutbox}', [ComplianceController::class, 'retry']);

    Route::get('/audit-log', [AuditLogController::class, 'index']);

    Route::get('/branches', [BranchController::class, 'index']);
    Route::post('/branches', [BranchController::class, 'store']);
    Route::get('/branches/{branch}', [BranchController::class, 'show']);
    Route::put('/branches/{branch}', [BranchController::class, 'update']);

    Route::get('/terminals', [TerminalController::class, 'index']);
    Route::post('/terminals', [TerminalController::class, 'store']);
    Route::get('/terminals/{terminal}', [TerminalController::class, 'show']);
    Route::put('/terminals/{terminal}', [TerminalController::class, 'update']);

    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{user}', [UserController::class, 'update']);

    Route::get('/customers', [CustomerController::class, 'index']);
    Route::post('/customers', [CustomerController::class, 'store']);
    Route::get('/customers/{customer}', [CustomerController::class, 'show']);
    Route::put('/customers/{customer}', [CustomerController::class, 'update']);

    Route::get('/customers-atl/status', [AtlImportController::class, 'status']);
    Route::post('/customers-atl/import', [AtlImportController::class, 'import']);
});
