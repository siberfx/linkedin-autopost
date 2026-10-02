<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Siberfx\LinkedInAutopost\Contracts\TokenStore;
use Siberfx\LinkedInAutopost\Data\StoredConnection;
use Siberfx\LinkedInAutopost\Tests\Fixtures\Post;
use Siberfx\LinkedInAutopost\Tests\Fixtures\PostStatus;

beforeEach(function () {
    $this->user = new GenericUser(['id' => 1, 'name' => 'Admin']);
    config(['linkedin-autopost.routes.after_connect' => '/admin/integrations']);
});

function stateFrom($response): string
{
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    return $query['state'];
}

it('requires the configured middleware', function () {
    // JSON requests: Testbench has no "login" route to redirect guests to.
    $this->getJson('/linkedin/redirect')->assertUnauthorized();
    $this->getJson('/linkedin/connection')->assertUnauthorized();
    $this->postJson('/linkedin/share/post/1')->assertUnauthorized();
});

it('redirects to LinkedIn with a state stored in the session', function () {
    $response = $this->actingAs($this->user)->get('/linkedin/redirect');

    $response->assertRedirectContains('https://www.linkedin.com/oauth/v2/authorization');
    expect(stateFrom($response))->toHaveLength(40)->toBe(session('linkedin-autopost.state'));
});

it('sends people back when credentials are missing', function () {
    config(['linkedin-autopost.client_id' => null]);

    $this->actingAs($this->user)->get('/linkedin/redirect')
        ->assertRedirect('/admin/integrations?linkedin=not_configured');
});

it('connects on a valid callback', function () {
    Http::fake([
        'https://www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'tok', 'expires_in' => 5184000]),
        'https://api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abc', 'name' => 'Ada']),
    ]);
    $state = stateFrom($this->actingAs($this->user)->get('/linkedin/redirect'));

    $this->actingAs($this->user)->get('/linkedin/callback?'.http_build_query(['code' => 'c', 'state' => $state]))
        ->assertRedirect('/admin/integrations?linkedin=connected');

    expect(app(TokenStore::class)->get()?->authorUrn)->toBe('urn:li:person:abc');
});

it('rejects a callback whose state does not match, and one replayed', function () {
    $state = stateFrom($this->actingAs($this->user)->get('/linkedin/redirect'));

    $this->actingAs($this->user)->get('/linkedin/callback?code=c&state=forged')
        ->assertRedirect('/admin/integrations?linkedin=invalid_state');
    $this->actingAs($this->user)->get('/linkedin/callback?'.http_build_query(['code' => 'c', 'state' => $state]))
        ->assertRedirect('/admin/integrations?linkedin=invalid_state'); // state was pulled by the first callback
});

it('passes LinkedIn errors back', function (array $query, array $expected) {
    $state = stateFrom($this->actingAs($this->user)->get('/linkedin/redirect'));

    $this->actingAs($this->user)->get('/linkedin/callback?'.http_build_query($query + ['state' => $state]))
        ->assertRedirect('/admin/integrations?'.http_build_query($expected));
})->with([
    'scope refused' => [
        ['error' => 'unauthorized_scope_error', 'error_description' => 'Scope "profile" is not authorized for your application'],
        ['linkedin' => 'error', 'reason' => 'Scope "profile" is not authorized for your application'],
    ],
    'cancelled' => [['error' => 'user_cancelled_authorize'], ['linkedin' => 'cancelled']],
]);

it('logs a failed exchange without the code or secret', function () {
    Log::spy();
    Http::fake(['https://www.linkedin.com/oauth/v2/accessToken' => Http::response(['error' => 'invalid_request', 'error_description' => 'authorization code not found'], 401)]);
    $state = stateFrom($this->actingAs($this->user)->get('/linkedin/redirect'));

    $this->actingAs($this->user)->get('/linkedin/callback?'.http_build_query(['code' => 'secret-code', 'state' => $state]))
        ->assertRedirect('/admin/integrations?linkedin=exchange_failed');

    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'LinkedIn token exchange failed.'
        && str_contains($context['error'], 'authorization code not found')
        && ! str_contains(json_encode($context), 'secret-code')
        && ! str_contains(json_encode($context), 'client-secret'));
});

it('shows and deletes the connection as JSON', function () {
    Http::fake([
        'https://www.linkedin.com/oauth/v2/introspectToken' => Http::response(['active' => true, 'status' => 'active']),
        'https://api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abc', 'name' => 'Ada']),
        'https://www.linkedin.com/oauth/v2/revoke' => Http::response('', 200),
    ]);
    app(TokenStore::class)->put(new StoredConnection('member-token', 'urn:li:person:abc', connectedAt: CarbonImmutable::now()));

    $this->actingAs($this->user)->getJson('/linkedin/connection')
        ->assertOk()
        ->assertJsonPath('data.connected', true)
        ->assertJsonPath('data.name', 'Ada')
        ->assertJsonMissingPath('data.access_token');

    $this->actingAs($this->user)->deleteJson('/linkedin/connection')
        ->assertOk()
        ->assertJsonPath('revoked', true)
        ->assertJsonPath('data.connected', false);
});

it('shares a model by morph alias', function () {
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:3'])]);
    app(TokenStore::class)->put(new StoredConnection('member-token', 'urn:li:person:abc'));
    $post = Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]);

    $this->actingAs($this->user)->postJson("/linkedin/share/post/{$post->id}")
        ->assertCreated()
        ->assertJsonPath('data.post_urn', 'urn:li:share:3');
});

it('answers 422 when not connected or not live, and 502 when LinkedIn refuses', function () {
    $post = Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]);
    $draft = Post::query()->create(['title' => 'Draft', 'status' => PostStatus::Draft]);

    $this->actingAs($this->user)->postJson("/linkedin/share/post/{$post->id}")
        ->assertStatus(422)->assertJsonPath('message', 'LinkedIn is not connected.');

    app(TokenStore::class)->put(new StoredConnection('member-token', 'urn:li:person:abc'));
    $this->actingAs($this->user)->postJson("/linkedin/share/post/{$draft->id}")->assertStatus(422);

    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response(['message' => 'Rate limited'], 429)]);
    $this->actingAs($this->user)->postJson("/linkedin/share/post/{$post->id}")
        ->assertStatus(502)->assertJsonPath('message', 'LinkedIn rejected the request: Rate limited');
});

// Review Focus 4: unknown alias, class name in the URL, deleted record.
it('answers 404 for anything that is not a known shareable record', function (string $type, string $id) {
    $this->actingAs($this->user)->postJson("/linkedin/share/{$type}/{$id}")->assertNotFound();
})->with([
    'unknown alias' => ['nope', '1'],
    'class name instead of alias' => [urlencode(Post::class), '1'],
    'deleted record' => ['post', '999'],
]);

it('registers its routes by default', function () {
    expect(app('router')->has('linkedin-autopost.callback'))->toBeTrue();
});
