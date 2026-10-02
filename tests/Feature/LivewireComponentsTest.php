<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Siberfx\LinkedInAutopost\Contracts\TokenStore;
use Siberfx\LinkedInAutopost\Data\StoredConnection;
use Siberfx\LinkedInAutopost\Livewire\ConnectionCard;
use Siberfx\LinkedInAutopost\Livewire\ShareButton;
use Siberfx\LinkedInAutopost\Models\LinkedInPost;
use Siberfx\LinkedInAutopost\Tests\Fixtures\Post;
use Siberfx\LinkedInAutopost\Tests\Fixtures\PostStatus;
use Siberfx\LinkedInAutopost\Tests\Fixtures\RelativeUrlPost;

beforeEach(function () {
    app(TokenStore::class)->put(new StoredConnection('member-token', 'urn:li:person:abc', name: 'Ada Lovelace',
        expiresAt: CarbonImmutable::now()->addDays(50), connectedAt: CarbonImmutable::now()));
});

it('registers the connection card by name', function () {
    Http::fake(['*' => Http::response('down', 503)]);

    $this->blade('<livewire:linkedin-autopost.connection />')->assertSee('Ada Lovelace');
});

it('disconnects from the card', function (string $theme) {
    config(['linkedin-autopost.ui.theme' => $theme]);
    Http::fake(['https://www.linkedin.com/oauth/v2/revoke' => Http::response('', 200), '*' => Http::response('down', 503)]);

    Livewire::test(ConnectionCard::class)
        ->assertSee('Ada Lovelace')
        ->assertSeeHtml('wire:click="disconnect"')
        ->call('disconnect')
        ->assertSee('LinkedIn disconnected.')
        ->assertSee('Not connected');

    expect(app(TokenStore::class)->get())->toBeNull();
})->with(['tailwind', 'bootstrap']);

it('shares from the button and shows the result', function (string $theme) {
    config(['linkedin-autopost.ui.theme' => $theme]);
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:11'])]);
    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));

    Livewire::test(ShareButton::class, ['model' => $post])
        ->assertSeeHtml('wire:click="share"')
        ->call('share')
        ->assertSee('Shared on LinkedIn.')
        ->assertSee('Share again')
        ->assertDispatched('linkedin-autopost-shared', urn: 'urn:li:share:11');

    expect(LinkedInPost::query()->for($post)->posted()->count())->toBe(1);
})->with(['tailwind', 'bootstrap']);

it('shows LinkedIn errors on the button', function () {
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response(['message' => 'Rate limited'], 429)]);
    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));

    Livewire::test(ShareButton::class, ['model' => $post])
        ->call('share')
        ->assertSee('LinkedIn rejected the request: Rate limited');
});

// UI review focus 1: locked identity.
it('refuses to change which record the button shares', function () {
    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));

    expect(fn () => Livewire::test(ShareButton::class, ['model' => $post])->set('shareableId', '999'))
        ->toThrow(Exception::class, 'locked');
    expect(fn () => Livewire::test(ShareButton::class, ['model' => $post])->set('shareableType', GenericUser::class))
        ->toThrow(Exception::class, 'locked');
});

it('shows an invalid post URL on the button instead of failing', function () {
    Http::fake();
    $post = RelativeUrlPost::query()->create(['title' => 'Hi', 'status' => 'published']);

    Livewire::test(ShareButton::class, ['model' => $post])
        ->call('share')
        ->assertOk()
        ->assertSee('LinkedIn posts need an absolute http(s) URL');
    Http::assertNothingSent();
});
