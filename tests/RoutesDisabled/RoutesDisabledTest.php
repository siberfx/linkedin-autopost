<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Siberfx\LinkedInAutopost\Tests\RoutesDisabledTestCase;

uses(RoutesDisabledTestCase::class);

it('registers no routes when disabled', function () {
    expect(app('router')->has('linkedin-autopost.callback'))->toBeFalse()
        ->and(app('router')->has('linkedin-autopost.share'))->toBeFalse();
});

it('installs without the callback route and says to set the redirect URI', function () {
    $envDir = sys_get_temp_dir().'/linkedin-autopost-'.uniqid();
    File::ensureDirectoryExists($envDir);
    File::put($envDir.'/.env', '');
    app()->useEnvironmentPath($envDir);

    try {
        $this->artisan('linkedin:install', ['--theme' => 'tailwind'])
            ->expectsOutputToContain('Set LINKEDIN_REDIRECT_URI to the URL you registered')
            ->assertSuccessful();
    } finally {
        File::deleteDirectory($envDir);
        File::delete(config_path('linkedin-autopost.php'));
        foreach (File::glob(database_path('migrations/*linkedin_*_table.php')) as $migration) {
            File::delete($migration);
        }
    }
});
