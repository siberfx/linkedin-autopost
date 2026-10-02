<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost;

use Illuminate\Support\ServiceProvider;

final class LinkedInAutopostServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/linkedin-autopost.php', 'linkedin-autopost');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/linkedin-autopost.php' => config_path('linkedin-autopost.php'),
            ], 'linkedin-autopost-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'linkedin-autopost-migrations');
        }
    }
}
