<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->envDir = sys_get_temp_dir().'/linkedin-autopost-'.uniqid();
    File::ensureDirectoryExists($this->envDir);
    app()->useEnvironmentPath($this->envDir);
});

afterEach(function () {
    File::deleteDirectory($this->envDir);
    File::delete(config_path('linkedin-autopost.php'));
    foreach (File::glob(database_path('migrations/*linkedin_*_table.php')) as $migration) {
        File::delete($migration);
    }
});

it('publishes config and migrations and writes the theme to .env', function () {
    File::put($this->envDir.'/.env', "APP_NAME=Test\n");

    $this->artisan('linkedin:install', ['--theme' => 'bootstrap'])
        ->expectsOutputToContain('LINKEDIN_UI_THEME=bootstrap')
        ->assertSuccessful();

    expect(File::get($this->envDir.'/.env'))->toContain("LINKEDIN_UI_THEME=bootstrap\n")
        ->and(File::exists(config_path('linkedin-autopost.php')))->toBeTrue()
        ->and(File::glob(database_path('migrations/*create_linkedin_connections_table.php')))->not->toBeEmpty();
});

it('asks for the theme and prints the Tailwind source line', function () {
    File::put($this->envDir.'/.env', '');

    $this->artisan('linkedin:install')
        ->expectsChoice('Which admin UI theme do you use?', 'tailwind', ['tailwind', 'bootstrap'])
        ->expectsOutputToContain("@source '../../vendor/siberfx/linkedin-autopost/resources/views/tailwind'")
        ->assertSuccessful();
});

it('does not overwrite an existing theme key', function () {
    File::put($this->envDir.'/.env', "LINKEDIN_UI_THEME=tailwind\n");

    $this->artisan('linkedin:install', ['--theme' => 'bootstrap'])->assertSuccessful();

    expect(File::get($this->envDir.'/.env'))->toBe("LINKEDIN_UI_THEME=tailwind\n");
});

it('rejects an unknown theme', function () {
    $this->artisan('linkedin:install', ['--theme' => 'bulma'])->assertFailed();
});

it('publishes the example model stub under its tag', function () {
    $target = base_path('stubs/linkedin-autopost/ShareableModel.stub');
    File::delete($target);

    $this->artisan('vendor:publish', ['--tag' => 'linkedin-autopost-stubs'])->assertSuccessful();

    expect(File::exists($target))->toBeTrue();

    File::delete($target);
    @rmdir(dirname($target));
});

it('prints the configured redirect URI and the gate to define', function () {
    config(['linkedin-autopost.redirect_uri' => 'https://example.com/admin/linkedin/callback']);
    File::put($this->envDir.'/.env', '');

    $this->artisan('linkedin:install', ['--theme' => 'bootstrap'])
        ->expectsOutputToContain('Register https://example.com/admin/linkedin/callback as an Authorized redirect URL')
        ->expectsOutputToContain("Gate::define('manage-linkedin-autopost', fn (User \$user) => \$user->is_admin);")
        ->assertSuccessful();
});

it('prints the package callback URL when no redirect URI is configured', function () {
    File::put($this->envDir.'/.env', '');

    $this->artisan('linkedin:install', ['--theme' => 'bootstrap'])
        ->expectsOutputToContain('Register http://localhost/linkedin/callback as an Authorized redirect URL')
        ->assertSuccessful();
});
