<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Siberfx\LinkedInAutopost\Http\Controllers\OAuthController;

Route::group([
    'prefix' => (string) config('linkedin-autopost.routes.prefix', 'linkedin'),
    'as' => (string) config('linkedin-autopost.routes.name', 'linkedin-autopost.'),
    'middleware' => (array) config('linkedin-autopost.routes.middleware', ['web', 'auth']),
], function (): void {
    Route::get('redirect', [OAuthController::class, 'redirect'])->name('redirect');
    Route::get('callback', [OAuthController::class, 'callback'])->name('callback');
});
