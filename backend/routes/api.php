<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DisputeController;
use App\Http\Controllers\Api\V1\FindingController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MetroController;
use App\Http\Controllers\Api\V1\OutageController;
use App\Http\Controllers\Api\V1\PropertyController;
use Illuminate\Support\Facades\Route;

/*
| API v1 (docs/api.md). Route parameters {property}, {bill}, {outage},
| {finding} and {dispute} only resolve to the authenticated user's own
| records (see AppServiceProvider); anything else is a 404.
*/

Route::prefix('v1')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);
    });

    Route::get('metros', [MetroController::class, 'index']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('me', [MeController::class, 'show']);
        Route::patch('me', [MeController::class, 'update']);
        Route::delete('me', [MeController::class, 'destroy'])->middleware('throttle:delete-account');
        Route::get('me/export', [MeController::class, 'export']);

        Route::get('dashboard', DashboardController::class);

        Route::get('properties', [PropertyController::class, 'index']);
        Route::post('properties', [PropertyController::class, 'store']);
        Route::get('properties/{property}', [PropertyController::class, 'show']);
        Route::patch('properties/{property}', [PropertyController::class, 'update']);
        Route::delete('properties/{property}', [PropertyController::class, 'destroy']);

        Route::get('properties/{property}/outages', [OutageController::class, 'index']);
        Route::post('properties/{property}/outages', [OutageController::class, 'store']);
        Route::delete('outages/{outage}', [OutageController::class, 'destroy']);

        Route::get('properties/{property}/bills', [BillController::class, 'index']);
        Route::post('properties/{property}/bills', [BillController::class, 'store']);
        Route::get('bills/{bill}', [BillController::class, 'show']);
        Route::put('bills/{bill}', [BillController::class, 'update']);
        Route::post('bills/{bill}/audit', [BillController::class, 'audit']);
        Route::get('bills/{bill}/file', [BillController::class, 'file']);
        Route::delete('bills/{bill}', [BillController::class, 'destroy']);

        Route::patch('findings/{finding}', [FindingController::class, 'update']);

        Route::get('disputes', [DisputeController::class, 'index']);
        Route::post('bills/{bill}/disputes', [DisputeController::class, 'store']);
        Route::get('disputes/{dispute}', [DisputeController::class, 'show']);
        Route::patch('disputes/{dispute}', [DisputeController::class, 'update']);
        Route::delete('disputes/{dispute}', [DisputeController::class, 'destroy']);
        Route::post('disputes/{dispute}/submit', [DisputeController::class, 'submit']);
        Route::post('disputes/{dispute}/events', [DisputeController::class, 'addEvent']);
        Route::post('disputes/{dispute}/escalate', [DisputeController::class, 'escalate']);
        Route::post('disputes/{dispute}/resolve', [DisputeController::class, 'resolve']);
    });
});
