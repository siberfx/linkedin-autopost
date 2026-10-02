<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Siberfx\LinkedInAutopost\Contracts\TokenStore;
use Siberfx\LinkedInAutopost\Data\StoredConnection;
use Siberfx\LinkedInAutopost\Stores\DatabaseTokenStore;

function storedConnection(string $token = 'member-token'): StoredConnection
{
    return new StoredConnection(
        accessToken: $token,
        authorUrn: 'urn:li:person:abc',
        name: 'Ada Lovelace',
        email: 'ada@example.com',
        picture: 'https://media.licdn.com/a.jpg',
        scopes: ['openid', 'w_member_social'],
        expiresAt: CarbonImmutable::parse('2026-12-01 10:00:00'),
        connectedAt: CarbonImmutable::parse('2026-10-02 10:00:00'),
    );
}

it('is the default TokenStore binding', function () {
    expect(app(TokenStore::class))->toBeInstanceOf(DatabaseTokenStore::class);
});

it('returns null when nothing is stored', function () {
    expect(app(TokenStore::class)->get())->toBeNull();
});

it('round-trips a connection', function () {
    $store = app(TokenStore::class);
    $store->put(storedConnection());

    $back = $store->get();

    expect($back)->toBeInstanceOf(StoredConnection::class)
        ->and($back->accessToken)->toBe('member-token')
        ->and($back->authorUrn)->toBe('urn:li:person:abc')
        ->and($back->name)->toBe('Ada Lovelace')
        ->and($back->scopes)->toBe(['openid', 'w_member_social'])
        ->and($back->expiresAt?->toDateTimeString())->toBe('2026-12-01 10:00:00');
});

it('encrypts the token at rest', function () {
    app(TokenStore::class)->put(storedConnection('plain-secret-token'));

    $raw = DB::table('linkedin_connections')->value('access_token');

    expect($raw)->not->toContain('plain-secret-token');
});

it('keeps a single row when connecting again', function () {
    $store = app(TokenStore::class);
    $store->put(storedConnection('first'));
    $store->put(storedConnection('second'));

    expect(DB::table('linkedin_connections')->count())->toBe(1)
        ->and($store->get()?->accessToken)->toBe('second');
});

it('forgets the connection', function () {
    $store = app(TokenStore::class);
    $store->put(storedConnection());
    $store->forget();

    expect($store->get())->toBeNull();
});

// Review Focus 1: APP_KEY rotated after connecting.
it('treats an undecryptable token as not connected', function () {
    app(TokenStore::class)->put(storedConnection());

    // Same effect as a new APP_KEY: Eloquent decrypts with a different key.
    Model::encryptUsing(new Encrypter(str_repeat('z', 32), 'AES-256-CBC'));

    try {
        expect(app(TokenStore::class)->get())->toBeNull();
    } finally {
        Model::encryptUsing(null);
    }
});
