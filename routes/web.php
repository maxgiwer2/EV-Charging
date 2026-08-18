<?php

declare(strict_types=1);

use App\Http\Controllers\Web\ReceiptReviewController;
use App\Http\Controllers\Web\SessionController;
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

Route::redirect('/', '/receipts');

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
    Route::get('receipts', [ReceiptReviewController::class, 'index'])->name('receipts.review.index');
    Route::get('receipts/{receipt}', [ReceiptReviewController::class, 'show'])->name('receipts.review.show');
    // Streams the image into the review page; policy-checked, never a
    // direct storage path (docs/03, AT-007).
    Route::get('receipts/{receipt}/file', [ReceiptReviewController::class, 'file'])->name('receipts.review.file');
    Route::post('receipts/{receipt}/verify', [ReceiptReviewController::class, 'verify'])->name('receipts.review.verify');
    Route::post('receipts/{receipt}/reject', [ReceiptReviewController::class, 'reject'])->name('receipts.review.reject');
});
