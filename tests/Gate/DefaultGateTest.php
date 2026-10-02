<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Siberfx\LinkedInAutopost\Contracts\TokenStore;
use Siberfx\LinkedInAutopost\Data\StoredConnection;
use Siberfx\LinkedInAutopost\Livewire\ConnectionCard;
use Siberfx\LinkedInAutopost\Livewire\ShareButton;
use Siberfx\LinkedInAutopost\Models\LinkedInPost;
use Siberfx\LinkedInAutopost\Tests\DefaultGateTestCase;
use Siberfx\LinkedInAutopost\Tests\Fixtures\Post;
use Siberfx\LinkedInAutopost\Tests\Fixtures\PostStatus;

uses(DefaultGateTestCase::class);

beforeEach(function () {
    Http::fake();
    $this->user = new GenericUser(['id' => 1, 'name' => 'Admin']);
    app(TokenStore::class)->put(new StoredConnection('member-token', 'urn:li:person:abc', name: 'Ada Lovelace',
        expiresAt: CarbonImmutable::now()->addDays(50), connectedAt: CarbonImmutable::now()));
    $this->post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));
});

it('defines the gate and denies everyone by default', function () {
    expect(Gate::has('manage-linkedin-autopost'))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('manage-linkedin-autopost'))->toBeFalse()
        ->and(Gate::allows('manage-linkedin-autopost'))->toBeFalse();
});

it('refuses every endpoint to a logged-in user the gate does not allow', function () {
    $this->actingAs($this->user)->getJson('/linkedin/redirect')->assertForbidden();
    $this->actingAs($this->user)->getJson('/linkedin/callback?code=c&state=s')->assertForbidden();
    $this->actingAs($this->user)->getJson('/linkedin/connection')->assertForbidden();
    $this->actingAs($this->user)->deleteJson('/linkedin/connection')->assertForbidden();
    $this->actingAs($this->user)->postJson("/linkedin/share/post/{$this->post->id}")->assertForbidden();
    $this->actingAs($this->user)
        ->postJson(URL::signedRoute('linkedin-autopost.share.signed', ['type' => 'post', 'id' => $this->post->id]))
        ->assertForbidden();

    expect(app(TokenStore::class)->get())->not->toBeNull()
        ->and(LinkedInPost::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('refuses the Livewire actions', function () {
    $this->actingAs($this->user);

    Livewire::test(ConnectionCard::class)->call('disconnect')->assertForbidden();
    Livewire::test(ShareButton::class, ['model' => $this->post])->call('share')->assertForbidden();

    expect(app(TokenStore::class)->get())->not->toBeNull()
        ->and(LinkedInPost::query()->count())->toBe(0);
    // Rendering the card may ask LinkedIn for the status; nothing is revoked or posted.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'revoke') || str_contains($request->url(), 'rest/posts'));
});

it('renders the share button disabled with the reason', function (string $theme) {
    config(['linkedin-autopost.ui.theme' => $theme]);

    $this->actingAs($this->user)->blade('<x-linkedin-autopost::share-button :model="$post" />', ['post' => $this->post])
        ->assertSee('You are not allowed to manage LinkedIn.')
        ->assertSeeHtml('disabled');
})->with(['tailwind', 'bootstrap']);

it('shows the connection read-only', function (string $theme, bool $livewire) {
    config(['linkedin-autopost.ui.theme' => $theme]);
    Http::fake(['*' => Http::response('down', 503)]);
    $this->actingAs($this->user);

    $view = $livewire
        ? Livewire::test(ConnectionCard::class)
        : $this->blade('<x-linkedin-autopost::connection />');

    $view->assertSee('Ada Lovelace')
        ->assertDontSee('Reconnect')
        ->assertDontSee('Disconnect')
        ->assertDontSee('Connect LinkedIn');
})->with(['tailwind', 'bootstrap'])->with(['blade' => false, 'livewire' => true]);

it('hides the connect button when not connected', function () {
    app(TokenStore::class)->forget();

    $this->actingAs($this->user)->blade('<x-linkedin-autopost::connection />')
        ->assertSee('Not connected')
        ->assertDontSee('Connect LinkedIn');
});
