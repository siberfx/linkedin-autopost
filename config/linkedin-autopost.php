<?php

declare(strict_types=1);

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
        'middleware' => ['web', 'auth'],
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
        // tailwind | bootstrap | the name of a folder of published views
        'theme' => env('LINKEDIN_UI_THEME', 'tailwind'),
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
