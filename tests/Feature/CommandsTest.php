<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Siberfx\LinkedInAutopost\Contracts\TokenStore;
use Siberfx\LinkedInAutopost\Data\StoredConnection;
use Siberfx\LinkedInAutopost\Events\TokenExpiringSoon;
use Siberfx\LinkedInAutopost\Models\LinkedInPost;
use Siberfx\LinkedInAutopost\Tests\Fixtures\Post;
use Siberfx\LinkedInAutopost\Tests\Fixtures\PostStatus;

function connectCommandTest(int $daysLeft = 50): void
{
    app(TokenStore::class)->put(new StoredConnection(
        'member-token', 'urn:li:person:abc', name: 'Ada',
        expiresAt: CarbonImmutable::now()->addDays($daysLeft)->addHour(),
        connectedAt: CarbonImmutable::now(),
    ));
}

it('shows the status without the token', function () {
    connectCommandTest();
    Http::fake(['*' => Http::response('down', 503)]);

    $this->artisan('linkedin:status')
        ->expectsOutputToContain('Ada')
        ->expectsOutputToContain('unknown')
        ->doesntExpectOutputToContain('member-token')
        ->assertSuccessful();
});

it('says when not connected', function () {
    $this->artisan('linkedin:status')->expectsOutputToContain('Not connected')->assertSuccessful();
});

it('disconnects after confirming', function () {
    connectCommandTest();
    Http::fake(['https://www.linkedin.com/oauth/v2/revoke' => Http::response('', 200)]);

    $this->artisan('linkedin:disconnect')
        ->expectsConfirmation('Disconnect LinkedIn and revoke the token?', 'yes')
        ->expectsOutputToContain('Disconnected')
        ->assertSuccessful();

    expect(app(TokenStore::class)->get())->toBeNull();
});

it('keeps the connection when the confirmation is declined', function () {
    connectCommandTest();

    $this->artisan('linkedin:disconnect')
        ->expectsConfirmation('Disconnect LinkedIn and revoke the token?', 'no')
        ->assertFailed();

    expect(app(TokenStore::class)->get())->not->toBeNull();
});

it('shares a model by alias or class, refusing a repeat without --force', function () {
    connectCommandTest();
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:9'])]);
    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));

    $this->artisan('linkedin:share', ['model' => 'post', 'id' => $post->id])
        ->expectsOutputToContain('urn:li:share:9')->assertSuccessful();
    $this->artisan('linkedin:share', ['model' => Post::class, 'id' => $post->id])
        ->expectsOutputToContain('already')->assertFailed();
    $this->artisan('linkedin:share', ['model' => 'post', 'id' => $post->id, '--force' => true])
        ->assertSuccessful();

    expect(LinkedInPost::query()->where('trigger', LinkedInPost::TRIGGER_CONSOLE)->count())->toBe(2);
});

it('fails clearly for an unknown model or record', function () {
    connectCommandTest();

    $this->artisan('linkedin:share', ['model' => 'nope', 'id' => 1])->expectsOutputToContain('not a shareable model')->assertFailed();
    $this->artisan('linkedin:share', ['model' => 'post', 'id' => 999])->expectsOutputToContain('not found')->assertFailed();
});

it('fires TokenExpiringSoon only inside the warning window', function (int $daysLeft, bool $fires) {
    Event::fake([TokenExpiringSoon::class]);
    connectCommandTest($daysLeft);
    Http::fake(['*' => Http::response('down', 503)]);

    $this->artisan('linkedin:check-token', ['--days' => 7])->assertSuccessful();

    $fires
        ? Event::assertDispatched(TokenExpiringSoon::class, fn (TokenExpiringSoon $e) => $e->daysLeft === $daysLeft)
        : Event::assertNotDispatched(TokenExpiringSoon::class);
})->with([
    'plenty left' => [30, false],
    'inside the window' => [5, true],
]);
