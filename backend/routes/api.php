<?php

use App\Http\Controllers\Api\AdminReportController;
use App\Http\Controllers\Api\AdminTagController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AssociationApplicationController;
use App\Http\Controllers\Api\AssociationApplicationReviewController;
use App\Http\Controllers\Api\AssociationController;
use App\Http\Controllers\Api\AttentionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\NfcTagController;
use App\Http\Controllers\Api\ProductPhotoController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\SuspiciousReportController;
use App\Http\Controllers\Api\VerifyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Public: the mobile app verifies a scanned NFC tag without logging in.
Route::post('/verify', VerifyController::class)->middleware('throttle:verify');
// Public: the app sends a report after a scan the server flagged (checked again on the server).
Route::post('/reports', [SuspiciousReportController::class, 'store'])->middleware('throttle:reports');
// Public: an association applies to be registered; staff decide. Nothing is created until approval.
Route::get('/municipalities', [AssociationApplicationController::class, 'municipalities']);
Route::post('/association-applications', [AssociationApplicationController::class, 'store'])->middleware('throttle:applications');
Route::post('/association-applications/status', [AssociationApplicationController::class, 'status'])->middleware('throttle:application-status');
Route::get('/product-photos/{filename}', [ProductPhotoController::class, 'show'])
    ->where('filename', '[0-9a-f\-]{36}\.(jpg|png|webp)');

Route::middleware(['auth:sanctum', 'password.changed'])->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user()->load('association');
    });

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword'])->middleware('throttle:change-password');

    Route::middleware('role:association')->group(function () {
        Route::get('/association/weavers', [AssociationController::class, 'weavers']);
        Route::post('/association/weavers', [AssociationController::class, 'storeWeaver']);
        Route::put('/association/weavers/{weaver}', [AssociationController::class, 'updateWeaver']);

        Route::get('/association/products', [AssociationController::class, 'products']);
        Route::post('/association/products', [AssociationController::class, 'storeProduct']);
        Route::put('/association/products/{product}', [AssociationController::class, 'updateProduct']);
        Route::post('/association/products/{product}/photo', [AssociationController::class, 'uploadProductPhoto']);
        Route::delete('/association/products/{product}/photo', [AssociationController::class, 'deleteProductPhoto']);

        Route::get('/association/scan-logs', [AssociationController::class, 'scanLogs']);
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
        Route::post('/staff/products/{product}/photo', [StaffController::class, 'uploadProductPhoto']);
        Route::delete('/staff/products/{product}/photo', [StaffController::class, 'deleteProductPhoto']);

        // Reports of suspicious tags: staff see their municipality, admin sees all.
        Route::get('/reports', [SuspiciousReportController::class, 'index']);
        Route::patch('/reports/{report}', [SuspiciousReportController::class, 'update']);

        // Association applications: staff decide their municipality's, admin decides all.
        Route::get('/applications', [AssociationApplicationReviewController::class, 'index']);
        Route::post('/applications/{application}/approve', [AssociationApplicationReviewController::class, 'approve']);
        Route::post('/applications/{application}/reject', [AssociationApplicationReviewController::class, 'reject']);
    });

    // Tag linking is shared by admin, staff and association users, each within their own scope.
    Route::middleware('role:admin,staff,association')->group(function () {
        // What needs attention now: new reports and recent suspicious scans, within each user's own scope.
        Route::get('/attention', AttentionController::class);

        Route::get('/nfc-tags', [NfcTagController::class, 'index']);
        Route::post('/nfc-tags', [NfcTagController::class, 'store']);
        Route::patch('/nfc-tags/{nfcTag}/revoke', [NfcTagController::class, 'revoke']);
        Route::post('/nfc-tags/{nfcTag}/secure-key', [NfcTagController::class, 'secureKey']);
        Route::post('/nfc-tags/{nfcTag}/secured', [NfcTagController::class, 'secured']);
    });

    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/summary', [AdminReportController::class, 'summary']);
        Route::get('/scan-logs', [AdminReportController::class, 'scanLogs']);

        Route::patch('/nfc-tags/{nfcTag}/reassign', [AdminTagController::class, 'reassign']);

        Route::get('/users', [AdminUserController::class, 'index']);
        Route::post('/users', [AdminUserController::class, 'store']);
        Route::put('/users/{user}', [AdminUserController::class, 'update']);
    });
});
