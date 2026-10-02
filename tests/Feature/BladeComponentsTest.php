<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Siberfx\LinkedInAutopost\Contracts\TokenStore;
use Siberfx\LinkedInAutopost\Data\StoredConnection;
use Siberfx\LinkedInAutopost\Models\LinkedInPost;
use Siberfx\LinkedInAutopost\Tests\Fixtures\Post;
use Siberfx\LinkedInAutopost\Tests\Fixtures\PostStatus;

function uiConnect(int $daysLeft = 50, string $name = 'Ada Lovelace', bool $offline = true): void
{
    app(TokenStore::class)->put(new StoredConnection(
        'member-token', 'urn:li:person:abc', name: $name, email: 'ada@example.com',
        expiresAt: CarbonImmutable::now()->addDays($daysLeft)->addHour(),
        connectedAt: CarbonImmutable::parse('2026-10-02'),
    ));
    if ($offline) {
        Http::fake(['*' => Http::response('down', 503)]); // status "unknown" → stored profile, no network
    }
}

dataset('themes', ['tailwind', 'bootstrap']);

it('shows Connect when not connected', function (string $theme) {
    config(['linkedin-autopost.ui.theme' => $theme]);

    $this->blade('<x-linkedin-autopost::connection />')
        ->assertSee('Not connected')
        ->assertSee(route('linkedin-autopost.redirect'), false)
        ->assertDontSee('Disconnect');
})->with('themes');

it('shows the connected member with Reconnect and Disconnect', function (string $theme) {
    config(['linkedin-autopost.ui.theme' => $theme]);
    uiConnect();

    $this->blade('<x-linkedin-autopost::connection />')
        ->assertSee('Ada Lovelace')
        ->assertSee('ada@example.com')
        ->assertSee('Status unknown')
        ->assertSee('Reconnect')
        ->assertSee('Disconnect')
        ->assertSee(route('linkedin-autopost.connection.destroy'), false)
        ->assertSee('name="_method" value="DELETE"', false)
        ->assertDontSee('member-token');
})->with('themes');

it('warns when the token expires soon', function () {
    uiConnect(daysLeft: 5, offline: false); // the first matching Http::fake stub wins, so do not register the 503 one
    Http::fake([
        'https://www.linkedin.com/oauth/v2/introspectToken' => Http::response(['active' => true, 'status' => 'active']),
        'https://api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abc', 'name' => 'Ada Lovelace']),
    ]);

    $this->blade('<x-linkedin-autopost::connection />')->assertSee('Expires in 5 days');
});

it('shows the callback result, escaped', function (string $theme) {
    config(['linkedin-autopost.ui.theme' => $theme]);
    request()->query->replace(['linkedin' => 'error', 'reason' => '<script>alert(1)</script>']);

    $this->blade('<x-linkedin-autopost::connection />')
        ->assertSee('LinkedIn refused the connection:')
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;', false);
})->with('themes');

it('renders an enabled share button with a signed action', function (string $theme) {
    config(['linkedin-autopost.ui.theme' => $theme]);
    uiConnect();
    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));

    $this->blade('<x-linkedin-autopost::share-button :model="$post" />', ['post' => $post])
        ->assertSee('Share on LinkedIn')
        ->assertSee('signature=', false)
        ->assertDontSee('disabled title=', false);
})->with('themes');

it('disables the share button with a reason', function (string $theme, Closure $arrange, string $reason) {
    config(['linkedin-autopost.ui.theme' => $theme]);
    $post = $arrange();

    $this->blade('<x-linkedin-autopost::share-button :model="$post" />', ['post' => $post])
        ->assertSee($reason)
        ->assertSee('disabled title=', false);
})->with('themes')->with([
    'not connected' => [fn () => Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published])), 'Connect LinkedIn first.'],
    'draft' => [function () {
        uiConnect();

        return Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Draft]);
    }, 'Only live items can be shared.'],
]);

it('offers a custom label with the last shared date', function () {
    uiConnect();
    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));
    LinkedInPost::query()->create(['shareable_type' => 'post', 'shareable_id' => $post->id, 'post_urn' => 'urn:li:share:1',
        'status' => LinkedInPost::STATUS_POSTED, 'trigger' => LinkedInPost::TRIGGER_MANUAL, 'posted_at' => CarbonImmutable::parse('2026-09-30')]);

    $this->blade('<x-linkedin-autopost::share-button :model="$post" label="Post it" />', ['post' => $post])
        ->assertSee('Post it')
        ->assertSee('30 Sep 2026');

    $this->blade('<x-linkedin-autopost::share-button :model="$post" />', ['post' => $post])->assertSee('Share again');
});

it('renders the session flash', function (string $theme) {
    config(['linkedin-autopost.ui.theme' => $theme]);
    session()->flash('linkedin-autopost.flash', ['type' => 'success', 'message' => 'Shared on LinkedIn.']);

    $this->blade('<x-linkedin-autopost::flash />')->assertSee('Shared on LinkedIn.');
})->with('themes');
