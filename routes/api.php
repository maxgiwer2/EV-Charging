<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChargingConnectorController;
use App\Http\Controllers\Api\V1\ChargingNetworkController;
use App\Http\Controllers\Api\V1\ChargingSessionController;
use App\Http\Controllers\Api\V1\ChargingStationController;
use App\Http\Controllers\Api\V1\VehicleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 (docs/07)
|--------------------------------------------------------------------------
|
| The /api/v1 prefix is applied in bootstrap/app.php via apiPrefix, so paths
| here are relative to it.
|
| Every route below the auth group requires a Sanctum token. Authorization is
| additionally enforced per record by policies, because authentication alone
| does not establish ownership (AT-007).
|
*/

Route::post('auth/login', [AuthController::class, 'login'])
    // Tighter limiter than the API default: password guessing is throttled
    // per email+IP (see AppServiceProvider).
    ->middleware('throttle:auth')
    ->name('auth.login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

    // Owned by the authenticated user.
    Route::apiResource('vehicles', VehicleController::class);
    Route::apiResource('charging-sessions', ChargingSessionController::class);

    // Shared reference data: readable by all, writable by admins only.
    Route::apiResource('networks', ChargingNetworkController::class)
        ->parameters(['networks' => 'charging_network']);
    Route::apiResource('stations', ChargingStationController::class)
        ->parameters(['stations' => 'charging_station']);
    Route::apiResource('connectors', ChargingConnectorController::class)
        ->parameters(['connectors' => 'charging_connector']);
});
