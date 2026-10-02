<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Siberfx\LinkedInAutopost\Contracts\TokenStore;
use Siberfx\LinkedInAutopost\Events\Shared;
use Siberfx\LinkedInAutopost\Events\ShareFailed;
use Siberfx\LinkedInAutopost\Events\TokenExpiringSoon;
use Siberfx\LinkedInAutopost\Listeners\SendNotifications;
use Siberfx\LinkedInAutopost\Support\Ui;

final class LinkedInAutopostServiceProvider extends ServiceProvider
{
    /** Who may connect, disconnect, see the connection and share. Define it in your app. */
    public const ABILITY = 'manage-linkedin-autopost';

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
        // Deny by default. Registered once every provider has booted, so the
        // app's own definition wins whatever the provider order.
        $this->app->booted(function (): void {
            if (! Gate::has(self::ABILITY)) {
                Gate::define(self::ABILITY, fn ($user = null): bool => false);
            }
        });

        // Always listening; the listener reads linkedin-autopost.notifications on each event.
        Event::listen([Shared::class, ShareFailed::class, TokenExpiringSoon::class], SendNotifications::class);

        if (Ui::enabled()) {
            $this->bootUi();
        }

        if (config('linkedin-autopost.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/linkedin-autopost.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/linkedin-autopost.php' => config_path('linkedin-autopost.php'),
            ], 'linkedin-autopost-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'linkedin-autopost-migrations');

            $this->publishes([
                __DIR__.'/../stubs/ShareableModel.stub' => base_path('stubs/linkedin-autopost/ShareableModel.stub'),
            ], 'linkedin-autopost-stubs');

            $this->commands([
                Console\InstallCommand::class,
                Console\StatusCommand::class,
                Console\DisconnectCommand::class,
                Console\ShareCommand::class,
                Console\CheckTokenCommand::class,
                Console\TestNotificationCommand::class,
            ]);
        }
    }

    /** Views, Blade and Livewire components; skipped in headless mode (ui.enabled = false). */
    private function bootUi(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'linkedin-autopost');
        Blade::componentNamespace('Siberfx\LinkedInAutopost\View\Components', 'linkedin-autopost');

        if (class_exists(\Livewire\Livewire::class)) {
            \Livewire\Livewire::component('linkedin-autopost.connection', Livewire\ConnectionCard::class);
            \Livewire\Livewire::component('linkedin-autopost.share-button', Livewire\ShareButton::class);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/linkedin-autopost'),
            ], 'linkedin-autopost-views');
        }
    }
}
