<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Siberfx\LinkedInAutopost\Exceptions\LinkedInRequestFailed;
use Siberfx\LinkedInAutopost\Exceptions\NotConfigured;
use Siberfx\LinkedInAutopost\OAuth\OAuthClient;

function authorizeQuery(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

it('builds the authorization url with the callback route by default', function () {
    $url = app(OAuthClient::class)->authorizationUrl('state-123');

    expect($url)->toStartWith('https://www.linkedin.com/oauth/v2/authorization?')
        ->and(authorizeQuery($url))->toBe([
            'response_type' => 'code',
            'client_id' => 'client-id',
            'redirect_uri' => route('linkedin-autopost.callback'),
            'state' => 'state-123',
            'scope' => 'openid profile email w_member_social',
        ]);
});

it('uses a configured redirect uri', function () {
    config(['linkedin-autopost.redirect_uri' => 'https://www.example.com/linkedin/callback']);

    expect(authorizeQuery(app(OAuthClient::class)->authorizationUrl('s'))['redirect_uri'])
        ->toBe('https://www.example.com/linkedin/callback');
});

it('refuses to build a url without credentials', function () {
    config(['linkedin-autopost.client_id' => null]);

    app(OAuthClient::class)->authorizationUrl('s');
})->throws(NotConfigured::class);

it('exchanges a code for a stored connection with the same redirect uri', function () {
    CarbonImmutable::setTestNow('2026-10-02 12:00:00');
    config(['linkedin-autopost.redirect_uri' => 'https://www.example.com/linkedin/callback']);
    Http::fake([
        'https://www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'member-token',
            'expires_in' => 5184000,
            'scope' => 'email,openid,profile,w_member_social',
        ]),
        'https://api.linkedin.com/v2/userinfo' => Http::response([
            'sub' => 'abc123',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'picture' => 'https://media.licdn.com/a.jpg',
        ]),
    ]);

    $connection = app(OAuthClient::class)->exchange('auth-code');

    expect($connection->accessToken)->toBe('member-token')
        ->and($connection->authorUrn)->toBe('urn:li:person:abc123')
        ->and($connection->name)->toBe('Ada Lovelace')
        ->and($connection->scopes)->toBe(['email', 'openid', 'profile', 'w_member_social'])
        ->and($connection->expiresAt?->toDateTimeString())->toBe('2026-12-01 12:00:00')
        ->and($connection->connectedAt?->toDateTimeString())->toBe('2026-10-02 12:00:00');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://www.linkedin.com/oauth/v2/accessToken'
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'auth-code'
        && $request['redirect_uri'] === 'https://www.example.com/linkedin/callback'
        && $request['client_id'] === 'client-id'
        && $request['client_secret'] === 'client-secret');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.linkedin.com/v2/userinfo'
        && $request->hasHeader('Authorization', 'Bearer member-token'));
});

it('fails the exchange with LinkedIn\'s error', function () {
    Http::fake(['https://www.linkedin.com/oauth/v2/accessToken' => Http::response([
        'error' => 'invalid_redirect_uri',
        'error_description' => 'Unable to retrieve access token: appid/redirect uri/code verifier does not match authorization code.',
    ], 400)]);

    expect(fn () => app(OAuthClient::class)->exchange('auth-code'))
        ->toThrow(LinkedInRequestFailed::class, 'appid/redirect uri/code verifier does not match');
});

it('fails the exchange when userinfo has no sub', function () {
    Http::fake([
        'https://www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 't', 'expires_in' => 10]),
        'https://api.linkedin.com/v2/userinfo' => Http::response(['name' => 'No Sub']),
    ]);

    expect(fn () => app(OAuthClient::class)->exchange('c'))->toThrow(LinkedInRequestFailed::class, 'no member id');
});

it('introspects a token, returning null when LinkedIn cannot answer', function () {
    Http::fake(['https://www.linkedin.com/oauth/v2/introspectToken' => Http::sequence()
        ->push(['active' => true, 'status' => 'active', 'expires_at' => 1795184000])
        ->push('down', 503)]);

    $client = app(OAuthClient::class);

    expect($client->introspect('t'))->toMatchArray(['active' => true, 'status' => 'active'])
        ->and($client->introspect('t'))->toBeNull();

    Http::assertSent(fn (Request $request) => $request['token'] === 't' && $request['client_secret'] === 'client-secret');
});

it('revokes a token', function () {
    Http::fake(['https://www.linkedin.com/oauth/v2/revoke' => Http::sequence()->push('', 200)->push(['error' => 'x'], 500)]);

    $client = app(OAuthClient::class);

    expect($client->revoke('t'))->toBeTrue()
        ->and($client->revoke('t'))->toBeFalse();
});
