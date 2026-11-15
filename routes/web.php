<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Newsletter\Http\Controllers\NewsletterController;
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
});
