<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Newsletter\Http\Controllers\NewsletterController;
use Modules\Newsletter\Http\Controllers\ProfileNewsletterController;
use Modules\Newsletter\Http\Middleware\EnsureNewsletterIsAvailable;

/*
|--------------------------------------------------------------------------
| Newsletter Module Web Routes
|--------------------------------------------------------------------------
*/

// Neither exists while the newsletter is not available: the same 404 for every request
Route::middleware(EnsureNewsletterIsAvailable::class)->group(function (): void {
    Route::get('/newsletter', [NewsletterController::class, 'show'])->name('newsletter.show');

    // Guests and signed-in people alike; someone signed in who must verify their email does it first
    Route::post('/newsletter', [NewsletterController::class, 'store'])
        ->middleware(['throttle:newsletter-subscribe', 'email.verified-if-required'])
        ->name('newsletter.store');

    // The «Boletín» section of the profile: the signed-in account's own address, never one from
    // the request. The same verification gate as the form
    Route::middleware(['auth', 'email.verified-if-required', 'throttle:newsletter-profile'])
        ->prefix('/perfil/boletin')
        ->group(function (): void {
            Route::get('/', [ProfileNewsletterController::class, 'show'])->name('newsletter.profile');
            Route::post('/', [ProfileNewsletterController::class, 'subscribe'])->name('newsletter.profile.subscribe');
            Route::delete('/', [ProfileNewsletterController::class, 'unsubscribe'])->name('newsletter.profile.unsubscribe');
        });
});
