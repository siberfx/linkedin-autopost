<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Siberfx\LinkedInAutopost\Contracts\TokenStore;
use Siberfx\LinkedInAutopost\Data\StoredConnection;
use Siberfx\LinkedInAutopost\Events\Connected;
use Siberfx\LinkedInAutopost\Events\Disconnected;
use Siberfx\LinkedInAutopost\Events\Shared;
use Siberfx\LinkedInAutopost\Exceptions\LinkedInRequestFailed;
use Siberfx\LinkedInAutopost\Exceptions\NotConnected;
use Siberfx\LinkedInAutopost\Exceptions\NotShareable;
use Siberfx\LinkedInAutopost\Facades\LinkedIn;
use Siberfx\LinkedInAutopost\Models\LinkedInPost;
use Siberfx\LinkedInAutopost\Tests\Fixtures\Post;
use Siberfx\LinkedInAutopost\Tests\Fixtures\PostStatus;

function connectForTest(string $token = 'member-token'): void
{
    app(TokenStore::class)->put(new StoredConnection(
        accessToken: $token,
        authorUrn: 'urn:li:person:abc',
        name: 'Stored Name',
        scopes: ['openid', 'w_member_social'],
        expiresAt: CarbonImmutable::now()->addDays(50),
        connectedAt: CarbonImmutable::now()->subDays(10),
    ));
}

function publishedPost(): Post
{
    return Post::query()->create(['title' => 'Hello', 'status' => PostStatus::Published]);
}

it('reports not connected without calling LinkedIn', function () {
    Http::fake();

    expect(LinkedIn::isConnected())->toBeFalse()
        ->and(LinkedIn::connection()->connected)->toBeFalse()
        ->and(LinkedIn::connection()->toArray())->toBe([
            'connected' => false, 'status' => null, 'name' => null, 'email' => null, 'picture' => null,
            'author_urn' => null, 'scopes' => [], 'connected_at' => null, 'expires_at' => null, 'days_left' => null,
        ]);

    Http::assertNothingSent();
});

it('describes an active connection from introspection and userinfo', function () {
    connectForTest();
    Http::fake([
        'https://www.linkedin.com/oauth/v2/introspectToken' => Http::response([
            'active' => true, 'status' => 'active', 'created_at' => 1790000000, 'expires_at' => 1795184000,
            'scope' => 'openid,profile,email,w_member_social',
        ]),
        'https://api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abc', 'name' => 'Ada Lovelace', 'email' => 'ada@example.com']),
    ]);

    $connection = LinkedIn::connection();

    expect($connection->connected)->toBeTrue()
        ->and($connection->status)->toBe('active')
        ->and($connection->name)->toBe('Ada Lovelace')
        ->and($connection->authorUrn)->toBe('urn:li:person:abc')
        ->and($connection->scopes)->toBe(['openid', 'profile', 'email', 'w_member_social'])
        ->and($connection->expiresAt?->getTimestamp())->toBe(1795184000)
        ->and($connection->toArray())->not->toHaveKey('access_token');
});

it('caches the status per token', function () {
    connectForTest();
    Http::fake([
        'https://www.linkedin.com/oauth/v2/introspectToken' => Http::response(['active' => true, 'status' => 'active']),
        'https://api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abc', 'name' => 'Ada']),
    ]);

    LinkedIn::connection();
    LinkedIn::connection();

    Http::assertSentCount(2);
});

it('reports an expired token without asking for the profile', function () {
    connectForTest();
    Http::fake([
        'https://www.linkedin.com/oauth/v2/introspectToken' => Http::response(['active' => false, 'status' => 'expired']),
        'https://api.linkedin.com/v2/userinfo' => Http::response([], 401),
    ]);

    expect(LinkedIn::connection()->status)->toBe('expired')
        ->and(LinkedIn::connection()->name)->toBe('Stored Name');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'userinfo'));
});

it('says unknown with the stored profile when LinkedIn is unreachable', function () {
    connectForTest();
    Http::fake(['*' => Http::response('down', 503)]);

    $connection = LinkedIn::connection();

    expect($connection->status)->toBe('unknown')
        ->and($connection->name)->toBe('Stored Name')
        ->and($connection->daysLeft())->toBe(49);
});

it('shares a live model, records the post and fires Shared', function () {
    Event::fake([Shared::class]);
    connectForTest();
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:7'])]);
    $post = publishedPost();

    $record = LinkedIn::share($post);

    expect($record->post_urn)->toBe('urn:li:share:7')
        ->and($record->status)->toBe(LinkedInPost::STATUS_POSTED)
        ->and($record->trigger)->toBe(LinkedInPost::TRIGGER_MANUAL)
        ->and($record->shareable_type)->toBe('post')
        ->and($record->posted_at)->not->toBeNull()
        ->and(LinkedIn::wasPosted($post))->toBeTrue();
    Event::assertDispatched(Shared::class, fn (Shared $e) => $e->post->is($record) && $e->shareable->is($post));
});

it('allows sharing the same model again', function () {
    connectForTest();
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::sequence()
        ->push(null, 201, ['x-restli-id' => 'urn:li:share:1'])
        ->push(null, 201, ['x-restli-id' => 'urn:li:share:2'])]);
    $post = publishedPost();

    LinkedIn::share($post);
    LinkedIn::share($post);

    expect(LinkedInPost::query()->for($post)->posted()->pluck('post_urn')->all())->toBe(['urn:li:share:1', 'urn:li:share:2']);
});

it('refuses to share when not connected or not live', function () {
    Http::fake();

    expect(fn () => LinkedIn::share(publishedPost()))->toThrow(NotConnected::class);

    connectForTest();
    $draft = Post::query()->create(['title' => 'Draft', 'status' => PostStatus::Draft]);

    expect(fn () => LinkedIn::share($draft))->toThrow(NotShareable::class);
    Http::assertNothingSent();
});

it('marks the cached status expired when LinkedIn rejects the token', function () {
    connectForTest();
    Http::fake([
        'https://www.linkedin.com/oauth/v2/introspectToken' => Http::response(['active' => true, 'status' => 'active']),
        'https://api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abc', 'name' => 'Ada']),
        'https://api.linkedin.com/rest/posts' => Http::response(['message' => 'Invalid access token'], 401),
    ]);
    LinkedIn::connection();

    expect(fn () => LinkedIn::share(publishedPost()))->toThrow(LinkedInRequestFailed::class);
    expect(LinkedIn::connection()->status)->toBe('expired')
        ->and(LinkedInPost::query()->count())->toBe(0);
});

it('completes a connection from an authorization code', function () {
    Event::fake([Connected::class]);
    Http::fake([
        'https://www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'new-token', 'expires_in' => 5184000]),
        'https://api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'xyz', 'name' => 'Grace Hopper']),
    ]);

    $connection = LinkedIn::completeConnection('code');

    expect($connection->status)->toBe('active')
        ->and($connection->name)->toBe('Grace Hopper')
        ->and(app(TokenStore::class)->get()?->accessToken)->toBe('new-token');
    Event::assertDispatched(Connected::class, fn (Connected $e) => $e->connection->authorUrn === 'urn:li:person:xyz');
});

it('disconnects, revoking at LinkedIn', function (int $revokeStatus, bool $revoked) {
    Event::fake([Disconnected::class]);
    connectForTest();
    Http::fake(['https://www.linkedin.com/oauth/v2/revoke' => Http::response('', $revokeStatus)]);

    expect(LinkedIn::disconnect())->toBe($revoked)
        ->and(LinkedIn::isConnected())->toBeFalse();
    Event::assertDispatched(Disconnected::class, fn (Disconnected $e) => $e->revoked === $revoked);
})->with([
    'revoked' => [200, true],
    'refused, still forgotten locally' => [500, false],
]);
