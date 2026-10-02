<?php

declare(strict_types=1);

use Siberfx\LinkedInAutopost\Notifications\Channels\SlackChannel;
use Siberfx\LinkedInAutopost\Notifications\Channels\TelegramChannel;
use Siberfx\LinkedInAutopost\Stores\DatabaseTokenStore;

return [

    /*
    | Credentials of your LinkedIn app (developer portal → My apps → Auth).
    */
    'client_id' => env('LINKEDIN_CLIENT_ID'),
    'client_secret' => env('LINKEDIN_CLIENT_SECRET'),

    /*
    | Must equal one of the app's "Authorized redirect URLs" exactly. Null uses
    | the package callback route, e.g. https://example.com/linkedin/callback.
    */
    'redirect_uri' => env('LINKEDIN_REDIRECT_URI'),

    'scopes' => ['openid', 'profile', 'email', 'w_member_social'],

    /*
    | LinkedIn-Version header (YYYYMM). LinkedIn retires each version about a
    | year after release; move this forward when they announce a sunset.
    */
    'api_version' => env('LINKEDIN_API_VERSION', '202609'),

    // PUBLIC or CONNECTIONS
    'visibility' => env('LINKEDIN_VISIBILITY', 'PUBLIC'),

    'routes' => [
        'enabled' => true,
        'prefix' => 'linkedin',
        'name' => 'linkedin-autopost.',
        // The gate denies everyone until your app defines it, e.g. in AppServiceProvider::boot():
        // Gate::define('manage-linkedin-autopost', fn (User $user) => $user->is_admin);
        'middleware' => ['web', 'auth', 'can:manage-linkedin-autopost'],
        // Where the OAuth callback sends the browser; ?linkedin=connected|cancelled|error|… is appended.
        'after_connect' => '/',
    ],

    'autopost' => [
        'enabled' => (bool) env('LINKEDIN_AUTOPOST', false),
        // Seeders and Artisan commands do not auto-post unless this is true.
        'in_console' => false,
        'queue_connection' => null,
        'queue' => null,
    ],

    'ui' => [
        // false = headless: no views, Blade or Livewire components, and the
        // routes answer JSON only. Use the facade, events and endpoints yourself.
        'enabled' => (bool) env('LINKEDIN_UI', true),
        // tailwind | bootstrap | the name of a folder of published views
        'theme' => env('LINKEDIN_UI_THEME', 'tailwind'),
    ],

    /*
    | Messages sent to Telegram and/or Slack when a post goes out, an automatic
    | share gives up, or the token is about to expire (linkedin:check-token).
    | Sent from a queued job; a failing channel never fails the share.
    | Try your settings with: php artisan linkedin:test-notification
    */
    'notifications' => [
        'enabled' => (bool) env('LINKEDIN_NOTIFY', false),

        'events' => [
            'shared' => true,
            'failed' => true,
            'token_expiring' => true,
        ],

        'queue_connection' => null,
        'queue' => null,
        'tries' => 3,
        'backoff' => [30, 120],

        'channels' => [
            'telegram' => [
                'enabled' => (bool) env('LINKEDIN_NOTIFY_TELEGRAM', false),
                'class' => TelegramChannel::class,
                // From @BotFather; add the bot to the chat first.
                'bot_token' => env('LINKEDIN_TELEGRAM_BOT_TOKEN'),
                // A user, group (-100…) or @channel id.
                'chat_id' => env('LINKEDIN_TELEGRAM_CHAT_ID'),
                // Forum topic id in a group with topics, or null.
                'thread_id' => env('LINKEDIN_TELEGRAM_THREAD_ID'),
            ],
            'slack' => [
                'enabled' => (bool) env('LINKEDIN_NOTIFY_SLACK', false),
                'class' => SlackChannel::class,
                // Incoming webhook URL (api.slack.com → Your apps → Incoming Webhooks).
                'webhook_url' => env('LINKEDIN_SLACK_WEBHOOK_URL'),
            ],
            // Your own: 'teams' => ['enabled' => true, 'class' => App\TeamsChannel::class, …],
            // a class implementing Siberfx\LinkedInAutopost\Contracts\NotificationChannel.
        ],
    ],

    'token_store' => DatabaseTokenStore::class,

    'http' => [
        'timeout' => 20,
    ],

    'job' => [
        'tries' => 3,
        'backoff' => [60, 300],
    ],

    'status_cache_ttl' => 300,

    'expiry_warning_days' => 7,

];
