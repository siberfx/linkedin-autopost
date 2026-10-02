<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Http;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Siberfx\LinkedInAutopost\Facades\LinkedIn;
use Siberfx\LinkedInAutopost\LinkedInAutopostServiceProvider;
use Siberfx\LinkedInAutopost\Tests\Fixtures\Post;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        Relation::morphMap(['post' => Post::class]);
    }

    protected function getPackageProviders($app): array
    {
        return array_values(array_filter([
            class_exists(LivewireServiceProvider::class) ? LivewireServiceProvider::class : null,
            LinkedInAutopostServiceProvider::class,
        ]));
    }

    protected function getPackageAliases($app): array
    {
        return ['LinkedIn' => LinkedIn::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('linkedin-autopost.client_id', 'client-id');
        $app['config']->set('linkedin-autopost.client_secret', 'client-secret');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    /** Laravel caches runningInConsole(); tests run in the CLI, so flip it to exercise the web path. */
    protected function pretendNotInConsole(): void
    {
        (fn () => $this->isRunningInConsole = false)->call($this->app);
    }
}
