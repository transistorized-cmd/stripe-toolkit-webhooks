<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TransistorizedCmd\StripeToolkit\Webhooks\Http\Controllers\DebugController;

$path = trim((string) config('stripe-webhooks.debug.path', 'stripe-webhooks-debug'), '/');

Route::middleware(config('stripe-webhooks.debug.middleware', ['web']))
    ->prefix($path)
    ->name('stripe-webhooks.debug.')
    ->group(function () {
        Route::get('/', [DebugController::class, 'index'])->name('index');
        Route::get('_form', [DebugController::class, 'form'])->name('form');
        Route::post('_send', [DebugController::class, 'send'])->name('send');
        Route::post('_trigger', [DebugController::class, 'trigger'])->name('trigger');
        Route::get('/{id}', [DebugController::class, 'show'])
            ->whereNumber('id')
            ->name('show');
    });
