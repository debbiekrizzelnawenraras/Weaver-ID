<?php

use App\Http\Controllers\Api\AssociationController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/association/weavers', [AssociationController::class, 'weavers']);
    Route::post('/association/weavers', [AssociationController::class, 'storeWeaver']);
    Route::get('/association/products', [AssociationController::class, 'products']);
});