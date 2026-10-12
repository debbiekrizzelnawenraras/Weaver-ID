<?php

use App\Http\Controllers\Api\AdminReportController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AssociationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\NfcTagController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\VerifyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Public: the mobile app verifies a scanned NFC tag without logging in.
Route::post('/verify', VerifyController::class)->middleware('throttle:verify');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::middleware('role:association')->group(function () {
        Route::get('/association/weavers', [AssociationController::class, 'weavers']);
        Route::post('/association/weavers', [AssociationController::class, 'storeWeaver']);
        Route::put('/association/weavers/{weaver}', [AssociationController::class, 'updateWeaver']);

        Route::get('/association/products', [AssociationController::class, 'products']);
        Route::post('/association/products', [AssociationController::class, 'storeProduct']);
    });

    // Staff scope: their own municipality. Admin uses the same routes and sees everything.
    Route::middleware('role:staff,admin')->group(function () {
        Route::get('/staff/associations', [StaffController::class, 'associations']);
        Route::post('/staff/associations', [StaffController::class, 'storeAssociation']);

        Route::get('/staff/weavers', [StaffController::class, 'weavers']);
        Route::post('/staff/weavers', [StaffController::class, 'storeWeaver']);
        Route::put('/staff/weavers/{weaver}', [StaffController::class, 'updateWeaver']);

        Route::get('/staff/products', [StaffController::class, 'products']);
        Route::post('/staff/products', [StaffController::class, 'storeProduct']);
        Route::put('/staff/products/{product}', [StaffController::class, 'updateProduct']);
    });

    // Tag linking is shared by admin, staff and association users, each within their own scope.
    Route::middleware('role:admin,staff,association')->group(function () {
        Route::get('/nfc-tags', [NfcTagController::class, 'index']);
        Route::post('/nfc-tags', [NfcTagController::class, 'store']);
        Route::patch('/nfc-tags/{nfcTag}/revoke', [NfcTagController::class, 'revoke']);
    });

    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/summary', [AdminReportController::class, 'summary']);
        Route::get('/scan-logs', [AdminReportController::class, 'scanLogs']);

        Route::get('/users', [AdminUserController::class, 'index']);
        Route::post('/users', [AdminUserController::class, 'store']);
        Route::put('/users/{user}', [AdminUserController::class, 'update']);
    });
});
