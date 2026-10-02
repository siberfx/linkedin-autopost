<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Siberfx\LinkedInAutopost\OAuth\OAuthClient;
use Siberfx\LinkedInAutopost\Support\Ui;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

final class InstallCommand extends Command
{
    private const THEMES = ['tailwind', 'bootstrap'];

    protected $signature = 'linkedin:install
        {--theme= : tailwind or bootstrap}
        {--headless : No UI: skip the theme, set LINKEDIN_UI=false and use the facade, events and JSON endpoints}';

    protected $description = 'Publish the LinkedIn Autopost config and migrations and choose the UI theme';

    public function handle(): int
    {
        $headless = (bool) $this->option('headless') || ! Ui::enabled();
        $theme = null;

        if (! $headless) {
            $theme = $this->option('theme') ?? $this->choice('Which admin UI theme do you use?', self::THEMES, 0);

            if (! in_array($theme, self::THEMES, true)) {
                $this->components->error('Choose tailwind or bootstrap.');

                return self::FAILURE;
            }
        }

        $this->callSilently('vendor:publish', ['--tag' => 'linkedin-autopost-config']);
        $this->callSilently('vendor:publish', ['--tag' => 'linkedin-autopost-migrations']);
        $this->components->info('Published config/linkedin-autopost.php and the migrations.');

        if ($headless) {
            $this->writeEnv('LINKEDIN_UI', 'false');
        } else {
            $this->writeEnv('LINKEDIN_UI_THEME', (string) $theme);
        }

        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. php artisan migrate');
        $this->line('  2. Set LINKEDIN_CLIENT_ID and LINKEDIN_CLIENT_SECRET in .env');
        $this->line('  3. '.$this->redirectStep());
        $this->line('  4. Define who may manage LinkedIn, e.g. in AppServiceProvider::boot() (everyone is refused until you do):');
        $this->line("     Gate::define('manage-linkedin-autopost', fn (User \$user) => \$user->is_admin);");
        $this->line($headless
            ? '  5. Send your admins to route(\'linkedin-autopost.redirect\') to connect; share with LinkedIn::share($model) or the JSON endpoints'
            : '  5. Put <x-linkedin-autopost::connection /> on your admin settings page');
        $this->line('  6. Optional: Telegram/Slack messages on each post, see "notifications" in config/linkedin-autopost.php');

        if ($theme === 'tailwind') {
            $this->newLine();
            $this->line('Tailwind v4: add to your CSS so the package classes are compiled:');
            $this->line("  @source '../../vendor/siberfx/linkedin-autopost/resources/views/tailwind';");
            $this->line("Tailwind v3: add './vendor/siberfx/linkedin-autopost/resources/views/tailwind/**/*.blade.php' to content in tailwind.config.js.");
        }

        return self::SUCCESS;
    }

    private function redirectStep(): string
    {
        try {
            $uri = $this->laravel->make(OAuthClient::class)->redirectUri();
        } catch (RouteNotFoundException) {
            // Routes disabled and no LINKEDIN_REDIRECT_URI: there is no callback URL to show.
            return 'Set LINKEDIN_REDIRECT_URI to the URL you registered as an Authorized redirect URL on your LinkedIn app';
        }

        return "Register {$uri} as an Authorized redirect URL on your LinkedIn app";
    }

    private function writeEnv(string $key, string $value): void
    {
        $env = $this->laravel->environmentFilePath();

        if (! File::exists($env)) {
            $this->components->warn("No .env found; add {$key}={$value} yourself.");

            return;
        }

        $contents = File::get($env);

        if (preg_match('/^'.preg_quote($key, '/').'=/m', $contents) === 1) {
            $this->components->info("{$key} is already set in .env; left unchanged.");

            return;
        }

        $separator = $contents === '' || str_ends_with($contents, "\n") ? '' : "\n";
        File::append($env, "{$separator}{$key}={$value}\n");
        $this->components->info("Added {$key}={$value} to .env.");
    }
}
