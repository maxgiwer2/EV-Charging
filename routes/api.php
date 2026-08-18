<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AssistantController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChargingConnectorController;
use App\Http\Controllers\Api\V1\ChargingNetworkController;
use App\Http\Controllers\Api\V1\ChargingSessionController;
use App\Http\Controllers\Api\V1\ChargingStationController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\InsightsController;
use App\Http\Controllers\Api\V1\ReceiptController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\TariffController;
use App\Http\Controllers\Api\V1\VehicleController;
use Illuminate\Http\Request;
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
    // Confirmation is a deliberate act, separate from editing: it is the point
    // where an entry becomes financial fact (AT-009).
    Route::post('charging-sessions/{charging_session}/confirm', [ChargingSessionController::class, 'confirm'])
        ->name('charging-sessions.confirm');
    Route::post('charging-sessions/{charging_session}/cancel', [ChargingSessionController::class, 'cancel'])
        ->name('charging-sessions.cancel');
    Route::post('charging-sessions/{charging_session}/reopen', [ChargingSessionController::class, 'reopen'])
        ->name('charging-sessions.reopen');

    /*
     * Dashboard (docs/07 -> Dashboard, docs/06).
     *
     * Every figure comes from CONFIRMED sessions only, so the dashboard
     * reconciles with the underlying records (AT-009).
     */
    Route::get('dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');
    Route::get('dashboard/trends', [DashboardController::class, 'trends'])->name('dashboard.trends');
    Route::get('dashboard/breakdowns', [DashboardController::class, 'breakdowns'])->name('dashboard.breakdowns');

    /*
     * Insights (docs/02 FR-018). Statistical, not AI: they inform decisions
     * about money, so they must be reproducible and explainable.
     */
    Route::get('insights/anomalies', [InsightsController::class, 'anomalies'])->name('insights.anomalies');
    Route::get('insights/forecast', [InsightsController::class, 'forecast'])->name('insights.forecast');

    /*
     * AI assistant (docs/02 FR-017).
     *
     * Advisory: every figure is computed by AnalyticsService and the model
     * only phrases it. Throttled separately because a local model takes
     * seconds per call and would otherwise consume the general API budget.
     */
    Route::post('assistant/ask', [AssistantController::class, 'ask'])
        ->middleware('throttle:assistant')
        ->name('assistant.ask');

    /*
     * Reports and exports (docs/07 -> Reports, FR-011/FR-012, AT-008).
     *
     * They share AnalyticsFilter with the dashboard, so an export contains
     * exactly the records the same filter selects.
     */
    Route::get('reports/charging', [ReportController::class, 'charging'])->name('reports.charging');
    Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');
    Route::get('reports/vehicles', fn (Request $r) => app(ReportController::class)->breakdown($r, 'vehicle'))
        ->name('reports.vehicles');
    Route::get('reports/stations', fn (Request $r) => app(ReportController::class)->breakdown($r, 'station'))
        ->name('reports.stations');
    Route::get('reports/networks', fn (Request $r) => app(ReportController::class)->breakdown($r, 'network'))
        ->name('reports.networks');

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

    /*
     * Tariffs (docs/07 -> Tariffs, docs/04 -> Admin Tariff).
     *
     * Versions are append-only in practice: one that has priced a session can
     * no longer be edited (AT-006), so a rate change publishes a new version.
     */
    Route::apiResource('tariffs', TariffController::class);
    Route::post('tariffs/{tariff}/versions', [TariffController::class, 'storeVersion'])
        ->name('tariffs.versions.store');
    Route::put('tariffs/{tariff}/versions/{version}', [TariffController::class, 'updateVersion'])
        ->name('tariffs.versions.update');

    // Shared reference data: readable by all, writable by admins only.
    Route::apiResource('networks', ChargingNetworkController::class)
        ->parameters(['networks' => 'charging_network']);
    Route::apiResource('stations', ChargingStationController::class)
        ->parameters(['stations' => 'charging_station']);
    Route::apiResource('connectors', ChargingConnectorController::class)
        ->parameters(['connectors' => 'charging_connector']);
});
