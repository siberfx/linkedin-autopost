<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Siberfx\LinkedInAutopost\Contracts\TokenStore;

final class LinkedInAutopostServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/linkedin-autopost.php', 'linkedin-autopost');

        $this->app->bind(
            TokenStore::class,
            fn ($app) => $app->make((string) config('linkedin-autopost.token_store')),
        );

        $this->app->scoped(LinkedInManager::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'linkedin-autopost');
        Blade::componentNamespace('Siberfx\\LinkedInAutopost\\View\\Components', 'linkedin-autopost');

        if (class_exists(\Livewire\Livewire::class)) {
            \Livewire\Livewire::component('linkedin-autopost.connection', Livewire\ConnectionCard::class);
            \Livewire\Livewire::component('linkedin-autopost.share-button', Livewire\ShareButton::class);
        }

        if (config('linkedin-autopost.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/linkedin-autopost.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/linkedin-autopost.php' => config_path('linkedin-autopost.php'),
            ], 'linkedin-autopost-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/linkedin-autopost'),
            ], 'linkedin-autopost-views');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'linkedin-autopost-migrations');

            $this->commands([
                Console\StatusCommand::class,
                Console\DisconnectCommand::class,
                Console\ShareCommand::class,
                Console\CheckTokenCommand::class,
            ]);
        }
    }
}
