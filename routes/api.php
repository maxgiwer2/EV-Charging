<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChargingConnectorController;
use App\Http\Controllers\Api\V1\ChargingNetworkController;
use App\Http\Controllers\Api\V1\ChargingSessionController;
use App\Http\Controllers\Api\V1\ChargingStationController;
use App\Http\Controllers\Api\V1\ReceiptController;
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

    /*
     * Receipts (docs/07 -> Receipts, docs/04 -> Receipt OCR flow).
     *
     * `download` is the only way to read a receipt file: the disk has no
     * public URL, so the ReceiptPolicy on this route is what keeps one user's
     * receipts out of another's reach (AT-007).
     */
    Route::get('receipts', [ReceiptController::class, 'index'])->name('receipts.index');
    Route::post('receipts', [ReceiptController::class, 'store'])->name('receipts.store');
    Route::get('receipts/{receipt}', [ReceiptController::class, 'show'])->name('receipts.show');
    Route::get('receipts/{receipt}/download', [ReceiptController::class, 'download'])->name('receipts.download');
    Route::post('receipts/{receipt}/ocr', [ReceiptController::class, 'ocr'])->name('receipts.ocr');
    Route::post('receipts/{receipt}/verify', [ReceiptController::class, 'verify'])->name('receipts.verify');
    Route::post('receipts/{receipt}/reject', [ReceiptController::class, 'reject'])->name('receipts.reject');

    // Shared reference data: readable by all, writable by admins only.
    Route::apiResource('networks', ChargingNetworkController::class)
        ->parameters(['networks' => 'charging_network']);
    Route::apiResource('stations', ChargingStationController::class)
        ->parameters(['stations' => 'charging_station']);
    Route::apiResource('connectors', ChargingConnectorController::class)
        ->parameters(['connectors' => 'charging_connector']);
});
