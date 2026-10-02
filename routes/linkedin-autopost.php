<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\Route;
use Siberfx\LinkedInAutopost\Http\Controllers\ConnectionController;
use Siberfx\LinkedInAutopost\Http\Controllers\OAuthController;
use Siberfx\LinkedInAutopost\Http\Controllers\ShareController;

Route::group([
    'prefix' => (string) config('linkedin-autopost.routes.prefix', 'linkedin'),
    'as' => (string) config('linkedin-autopost.routes.name', 'linkedin-autopost.'),
    'middleware' => (array) config('linkedin-autopost.routes.middleware', ['web', 'auth', 'can:manage-linkedin-autopost']),
], function (): void {
    Route::get('redirect', [OAuthController::class, 'redirect'])->name('redirect');
    Route::get('callback', [OAuthController::class, 'callback'])->name('callback');
    Route::get('connection', [ConnectionController::class, 'show'])->name('connection.show');
    Route::delete('connection', [ConnectionController::class, 'destroy'])->name('connection.destroy');
    Route::post('share', [ShareController::class, 'signed'])
        ->middleware(ValidateSignature::class)
        ->name('share.signed');
    Route::post('share/{type}/{id}', ShareController::class)->name('share');
});
