<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes of the newsletter module
|--------------------------------------------------------------------------
|
| The module loader already applies the "api" middleware and the "/api"
| prefix: these routes answer under /api/newsletter/...
|
*/

Route::prefix('newsletter')
    ->name('newsletter.api.')
    ->group(function (): void {
        // Route::apiResource('items', ItemController::class);
    });
