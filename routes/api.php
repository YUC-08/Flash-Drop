<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DropController;
use App\Http\Controllers\Api\ReservationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public rotalar
    Route::post('/auth/token', [AuthController::class, 'token']);

    // auth:sanctum ile korunan rotalar
    Route::middleware('auth:sanctum')->group(function () {
        // GET /api/v1/drops (drops:read yeteneği gerektirir)
        Route::get('/drops', [DropController::class, 'index'])
            ->middleware('ability:drops:read');

        // POST /api/v1/drops/{drop}/reserve (drops:reserve yeteneği + drop.window kontrolü)
        Route::post('/drops/{drop}/reserve', [ReservationController::class, 'reserve'])
            ->middleware(['ability:drops:reserve', 'drop.window']);

        // POST /api/v1/reservations/{reservation}/confirm (drops:reserve yeteneği gerektirir)
        Route::post('/reservations/{reservation}/confirm', [ReservationController::class, 'confirm'])
            ->middleware('ability:drops:reserve');
    });
});
