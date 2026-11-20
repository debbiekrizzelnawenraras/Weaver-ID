<?php

use App\Http\Controllers\Api\AdminReportController;
use App\Http\Controllers\Api\AdminTagController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AssociationApplicationController;
use App\Http\Controllers\Api\AssociationApplicationReviewController;
use App\Http\Controllers\Api\AssociationController;
use App\Http\Controllers\Api\AttentionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailCodeController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\NfcTagController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ProductPhotoController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\SuspiciousReportController;
use App\Http\Controllers\Api\TagAssignmentController;
use App\Http\Controllers\Api\VerifyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Public: forgot password, for every role. A 6-digit code goes to the account's email, then the code
// is typed with a new password. The answers do not say whether an email has an account.
Route::post('/password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:password-forgot');
Route::post('/password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset');

// Public: the mobile app verifies a scanned NFC tag without logging in.
Route::post('/verify', VerifyController::class)->middleware('throttle:verify');
// Public: the app sends a report after a scan the server flagged (checked again on the server).
Route::post('/reports', [SuspiciousReportController::class, 'store'])->middleware('throttle:reports');
// Public: an association applies to be registered; the administrator decides. Nothing is created until approval.
Route::get('/municipalities', [AssociationApplicationController::class, 'municipalities']);
Route::post('/email-codes', [EmailCodeController::class, 'send'])->middleware('throttle:email-codes');
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

    // Association accounts: their own products and the tags sent to them.
    Route::middleware('role:association')->group(function () {
        Route::get('/association/products', [AssociationController::class, 'products']);
        Route::post('/association/products', [AssociationController::class, 'storeProduct']);
        Route::put('/association/products/{product}', [AssociationController::class, 'updateProduct']);
        Route::post('/association/products/{product}/photo', [AssociationController::class, 'uploadProductPhoto']);
        Route::delete('/association/products/{product}/photo', [AssociationController::class, 'deleteProductPhoto']);

        Route::get('/association/scan-logs', [AssociationController::class, 'scanLogs']);
    });

    // Lists that staff (their municipality) and admin (everything, read only) both open.
    Route::middleware('role:staff,admin')->group(function () {
        Route::get('/staff/associations', [StaffController::class, 'associations']);
        Route::get('/staff/products', [StaffController::class, 'products']);

        Route::get('/inventory', [InventoryController::class, 'summary']);
        Route::get('/inventory/tags', [InventoryController::class, 'tags']);
    });

    // Staff only: register associations and products, and run the tag inventory.
    // The administrator does not register records (panel directive, 19 Nov 2026).
    Route::middleware('role:staff')->group(function () {
        Route::post('/staff/associations', [StaffController::class, 'storeAssociation']);
        Route::get('/staff/association-applications', [StaffController::class, 'applications']);

        Route::post('/staff/products', [StaffController::class, 'storeProduct']);
        Route::put('/staff/products/{product}', [StaffController::class, 'updateProduct']);
        Route::post('/staff/products/{product}/photo', [StaffController::class, 'uploadProductPhoto']);
        Route::delete('/staff/products/{product}/photo', [StaffController::class, 'deleteProductPhoto']);

        Route::post('/inventory/batches', [InventoryController::class, 'storeBatch']);
        Route::post('/inventory/batches/{batch}/tags', [InventoryController::class, 'addToBatch']);
        Route::post('/inventory/send', [InventoryController::class, 'send']);
        Route::post('/inventory/tags/{nfcTag}/return', [InventoryController::class, 'returnToStock']);
        Route::post('/inventory/tags/{nfcTag}/damaged', [InventoryController::class, 'markDamaged']);
    });

    Route::middleware('role:admin,staff,association')->group(function () {
        // What needs attention now: new reports and recent suspicious scans, within each user's own scope.
        Route::get('/attention', AttentionController::class);

        Route::get('/nfc-tags', [NfcTagController::class, 'index']);
        // Revoking stays open to the administrator too, so a tag can be stopped after a report.
        Route::patch('/nfc-tags/{nfcTag}/revoke', [NfcTagController::class, 'revoke']);
    });

    // Only the administrator decides association applications and reviews reports of suspicious tags.
    Route::middleware('role:admin')->group(function () {
        Route::get('/reports', [SuspiciousReportController::class, 'index']);
        Route::patch('/reports/{report}', [SuspiciousReportController::class, 'update']);

        // Delivery lists: which tags go to which Negosyo Center, and alerts when one reaches the wrong center.
        Route::get('/admin/tag-assignments', [TagAssignmentController::class, 'index']);
        Route::get('/admin/tag-assignments/list', [TagAssignmentController::class, 'list']);
        Route::post('/admin/tag-assignments', [TagAssignmentController::class, 'store']);
        Route::post('/admin/tag-assignments/move', [TagAssignmentController::class, 'move']);
        Route::post('/admin/tag-alerts/{alert}/move', [TagAssignmentController::class, 'moveAlert']);
        Route::post('/admin/tag-alerts/{alert}/dismiss', [TagAssignmentController::class, 'dismissAlert']);

        Route::get('/applications', [AssociationApplicationReviewController::class, 'index']);
        Route::post('/applications/{application}/approve', [AssociationApplicationReviewController::class, 'approve']);
        Route::post('/applications/{application}/reject', [AssociationApplicationReviewController::class, 'reject']);
    });

    // Linking and securing tags: staff and association accounts, never the administrator.
    Route::middleware('role:staff,association')->group(function () {
        Route::post('/nfc-tags', [NfcTagController::class, 'store']);
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
