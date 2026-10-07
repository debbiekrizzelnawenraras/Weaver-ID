<?php

use App\Http\Controllers\Api\AssociationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StaffController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::middleware('role:association')->group(function () {
        Route::get('/association/weavers', [AssociationController::class, 'weavers']);
        Route::post('/association/weavers', [AssociationController::class, 'storeWeaver']);
        Route::put('/association/weavers/{weaver}', [AssociationController::class, 'updateWeaver']);

        Route::get('/association/products', [AssociationController::class, 'products']);
        Route::post('/association/products', [AssociationController::class, 'storeProduct']);
    });
    
    Route::middleware('role:staff')->group(function () {
        Route::get('/staff/weavers', [StaffController::class, 'weavers']);
        Route::post('/staff/weavers', [StaffController::class, 'storeWeaver']);
        Route::put('/staff/weavers/{weaver}', [StaffController::class, 'updateWeaver']);

        Route::get('/staff/products', [StaffController::class, 'products']);
        Route::post('/staff/products', [StaffController::class, 'storeProduct']);
        Route::put('/staff/products/{product}', [StaffController::class, 'updateProduct']);
    });
});