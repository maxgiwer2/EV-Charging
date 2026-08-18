<?php

declare(strict_types=1);

use App\Http\Controllers\Web\BudgetController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\QuickEntryController;
use App\Http\Controllers\Web\ReceiptReviewController;
use App\Http\Controllers\Web\ReceiptUploadController;
use App\Http\Controllers\Web\SessionController;
use App\Http\Controllers\Web\VehicleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| The server-rendered UI. Authentication is by session cookie rather than the
| API's bearer token, so these routes get CSRF protection and no token needs
| to be exposed to JavaScript.
|
*/

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function (): void {
    Route::get('login', [SessionController::class, 'create'])->name('web.login');
    Route::post('login', [SessionController::class, 'store'])
        // Same limiter as the API: 5 attempts per minute per email+IP.
        ->middleware('throttle:auth')
        ->name('web.login.attempt');
});

Route::post('logout', [SessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('web.logout');

Route::middleware('auth')->group(function (): void {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Quick entry (docs/04): the path a driver uses at the charger.
    Route::get('quick-add', [QuickEntryController::class, 'create'])->name('sessions.quick-entry');
    Route::post('quick-add', [QuickEntryController::class, 'store'])->name('sessions.quick-entry.store');

    // Budgets (docs/02 FR-013). Names are namespaced because the API
    // resource already registers budgets.*.
    Route::get('budgets', [BudgetController::class, 'index'])->name('budgets.manage.index');
    Route::get('budgets/create', [BudgetController::class, 'create'])->name('budgets.manage.create');
    Route::post('budgets', [BudgetController::class, 'store'])->name('budgets.manage.store');
    Route::get('budgets/{budget}/edit', [BudgetController::class, 'edit'])->name('budgets.manage.edit');
    Route::put('budgets/{budget}', [BudgetController::class, 'update'])->name('budgets.manage.update');
    Route::delete('budgets/{budget}', [BudgetController::class, 'destroy'])->name('budgets.manage.destroy');

    // Vehicles (docs/02 FR-002).
    Route::get('vehicles', [VehicleController::class, 'index'])->name('vehicles.manage.index');
    Route::get('vehicles/create', [VehicleController::class, 'create'])->name('vehicles.manage.create');
    Route::post('vehicles', [VehicleController::class, 'store'])->name('vehicles.manage.store');
    Route::get('vehicles/{vehicle}/edit', [VehicleController::class, 'edit'])->name('vehicles.manage.edit');
    Route::put('vehicles/{vehicle}', [VehicleController::class, 'update'])->name('vehicles.manage.update');
    Route::delete('vehicles/{vehicle}', [VehicleController::class, 'destroy'])->name('vehicles.manage.destroy');

    // Receipt upload (docs/04 -> Scan/Upload). Declared before the
    // {receipt} routes so "upload" is not captured as a receipt id.
    Route::get('receipts/upload', [ReceiptUploadController::class, 'create'])->name('receipts.upload');
    Route::post('receipts/upload', [ReceiptUploadController::class, 'store'])->name('receipts.upload.store');

    Route::get('receipts', [ReceiptReviewController::class, 'index'])->name('receipts.review.index');
    Route::get('receipts/{receipt}', [ReceiptReviewController::class, 'show'])->name('receipts.review.show');
    // Streams the image into the review page; policy-checked, never a
    // direct storage path (docs/03, AT-007).
    Route::get('receipts/{receipt}/file', [ReceiptReviewController::class, 'file'])->name('receipts.review.file');
    Route::post('receipts/{receipt}/verify', [ReceiptReviewController::class, 'verify'])->name('receipts.review.verify');
    Route::post('receipts/{receipt}/reject', [ReceiptReviewController::class, 'reject'])->name('receipts.review.reject');
});
