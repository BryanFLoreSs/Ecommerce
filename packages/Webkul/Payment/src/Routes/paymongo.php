<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Webkul\Payment\Http\Controllers\PaymongoController;

Route::middleware(['web', 'shop'])->prefix('paymongo')->group(function () {
    Route::get('redirect', [PaymongoController::class, 'redirect'])->name('paymongo.redirect');
    Route::get('success/{orderId}', [PaymongoController::class, 'success'])->middleware('signed')->name('paymongo.success');
    Route::get('cancel/{orderId}', [PaymongoController::class, 'cancel'])->middleware('signed')->name('paymongo.cancel');
});

Route::post('paymongo/webhook', [PaymongoController::class, 'webhook'])
    ->middleware(['web', 'throttle:120,1'])
    ->withoutMiddleware(PreventRequestForgery::class)
    ->name('paymongo.webhook');
