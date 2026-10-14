<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Siberfx\LinkedInAutopost\Contracts\TokenStore;
use Siberfx\LinkedInAutopost\Data\Page;
use Siberfx\LinkedInAutopost\Data\StoredConnection;
use Siberfx\LinkedInAutopost\Events\Connected;
use Siberfx\LinkedInAutopost\Events\ShareFailed;
use Siberfx\LinkedInAutopost\Exceptions\LinkedInRequestFailed;
use Siberfx\LinkedInAutopost\Facades\LinkedIn;
use Siberfx\LinkedInAutopost\Jobs\ShareOnLinkedIn;
use Siberfx\LinkedInAutopost\LinkedInManager;
use Siberfx\LinkedInAutopost\Livewire\ConnectionCard;
use Siberfx\LinkedInAutopost\Models\LinkedInPost;
use Siberfx\LinkedInAutopost\Support\Columns;
use Siberfx\LinkedInAutopost\Tests\Fixtures\Post;
use Siberfx\LinkedInAutopost\Tests\Fixtures\PostStatus;

const MEMBER = 'urn:li:person:abc';
const ACME = 'urn:li:organization:111';
const GLOBEX = 'urn:li:organization:222';

beforeEach(function () {
    Columns::flush();
    $this->user = new GenericUser(['id' => 1, 'name' => 'Admin']);
    config(['linkedin-autopost.routes.after_connect' => '/admin/integrations']);
});

/** A connection that may post as the member and two LinkedIn Pages. */
function connectWithPages(array $selected = [MEMBER]): void
{
    app(TokenStore::class)->put(new StoredConnection(
        accessToken: 'member-token',
        authorUrn: MEMBER,
        name: 'Ada',
        expiresAt: CarbonImmutable::now()->addDays(50),
        connectedAt: CarbonImmutable::now(),
        pages: [
            new Page(MEMBER, Page::TYPE_MEMBER, 'Ada'),
            new Page(ACME, Page::TYPE_ORGANIZATION, 'Acme'),
            new Page(GLOBEX, Page::TYPE_ORGANIZATION, 'Globex'),
        ],
        selectedPages: $selected,
    ));
    app()->forgetScopedInstances();
    LinkedIn::clearResolvedInstance(LinkedInManager::class);
}

function livePost(): Post
{
    return Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hello', 'status' => PostStatus::Published]));
}

/** LinkedIn's answers for a member who administers Acme, analyses Initech and content-manages Globex. */
function fakeOrganizations(): void
{
    Http::fake([
        'https://www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'tok', 'expires_in' => 5184000, 'scope' => 'openid,w_member_social,r_organization_admin,w_organization_social']),
        'https://api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abc', 'name' => 'Ada', 'picture' => 'https://media.example/ada.jpg']),
        'https://api.linkedin.com/rest/organizationAcls*' => Http::response(['elements' => [
            ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => ACME],
            ['role' => 'ANALYST', 'state' => 'APPROVED', 'organization' => 'urn:li:organization:333'],
            ['role' => 'CONTENT_ADMINISTRATOR', 'state' => 'APPROVED', 'organizationalTarget' => GLOBEX],
            ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => ACME],
        ]]),
        'https://api.linkedin.com/rest/organizations/111' => Http::response(['localizedName' => 'Acme']),
        'https://api.linkedin.com/rest/organizations/222' => Http::response(['message' => 'Not found'], 404),
    ]);
}

function connectThroughCallback($test): void
{
    $location = $test->actingAs($test->user)->get('/linkedin/redirect')->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $test->actingAs($test->user)->get('/linkedin/callback?'.http_build_query(['code' => 'c', 'state' => $query['state']]))
        ->assertRedirect('/admin/integrations?linkedin=connected');
}

it('asks for the organization scopes only when organizations are enabled', function () {
    $scope = function () {
        $location = $this->actingAs($this->user)->get('/linkedin/redirect')->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query['scope'];
    };

    expect($scope())->toBe('openid profile email w_member_social');

    config(['linkedin-autopost.organizations.enabled' => true]);

    expect($scope())->toBe('openid profile email w_member_social r_organization_admin w_organization_social');
});

it('lists the member and the pages they may post as after connecting, with the member selected', function () {
    config(['linkedin-autopost.organizations.enabled' => true]);
    fakeOrganizations();
    Event::fake([Connected::class]);

    connectThroughCallback($this);

    Event::assertDispatched(Connected::class, fn (Connected $event) => array_column($event->connection->toArray()['pages'], 'id') === [MEMBER, ACME, GLOBEX]);

    $this->actingAs($this->user)->getJson('/linkedin/connection/pages')
        ->assertOk()
        ->assertExactJson(['data' => [
            'pages' => [
                ['id' => MEMBER, 'type' => 'member', 'name' => 'Ada', 'picture' => 'https://media.example/ada.jpg', 'selected' => true],
                ['id' => ACME, 'type' => 'organization', 'name' => 'Acme', 'picture' => null, 'selected' => false],
                // A Page whose details cannot be read is still listed, without a name.
                ['id' => GLOBEX, 'type' => 'organization', 'name' => null, 'picture' => null, 'selected' => false],
            ],
            'selected_pages' => [MEMBER],
        ]]);

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.linkedin.com/rest/organizationAcls')
        && $request['q'] === 'roleAssignee' && $request['state'] === 'APPROVED'
        && $request->hasHeader('LinkedIn-Version') && $request->hasHeader('Authorization', 'Bearer tok'));
});

it('returns the pages from the completeConnection call itself', function () {
    config(['linkedin-autopost.organizations.enabled' => true]);
    fakeOrganizations();

    $connection = LinkedIn::completeConnection('code');

    expect(array_column($connection->toArray()['pages'], 'selected', 'id'))->toBe([MEMBER => true, ACME => false, GLOBEX => false]);
});

it('never asks LinkedIn for pages when organizations are off', function () {
    fakeOrganizations();

    connectThroughCallback($this);

    expect(array_map(fn (Page $page) => $page->id, LinkedIn::pages()))->toBe([MEMBER]);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'organization'));
});

it('connects anyway when the pages cannot be listed', function () {
    config(['linkedin-autopost.organizations.enabled' => true]);
    Log::spy();
    Http::fake([
        'https://www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'tok', 'expires_in' => 5184000]),
        'https://api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abc', 'name' => 'Ada']),
        'https://api.linkedin.com/rest/organizationAcls*' => Http::response(['message' => 'Not enough permissions'], 403),
    ]);

    connectThroughCallback($this);

    expect(array_map(fn (Page $page) => $page->id, LinkedIn::pages()))->toBe([MEMBER]);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => $message === 'Could not list the LinkedIn Pages of the member.');
});

it('treats a connection stored before pages existed as the member only', function () {
    app(TokenStore::class)->put(new StoredConnection('member-token', MEMBER, name: 'Ada', connectedAt: CarbonImmutable::now()));

    expect(LinkedIn::pages())->toEqual([new Page(MEMBER, Page::TYPE_MEMBER, 'Ada')])
        ->and(LinkedIn::selectedPages())->toEqual([new Page(MEMBER, Page::TYPE_MEMBER, 'Ada')]);
});

it('selects several pages through the JSON endpoint and shows them in the connection', function () {
    connectWithPages();
    Http::fake(['https://www.linkedin.com/oauth/v2/introspectToken' => Http::response(['active' => true]), 'https://api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abc'])]);
    LinkedIn::connection(); // cached with the member selected

    $this->actingAs($this->user)->putJson('/linkedin/connection/pages', ['pages' => [ACME, GLOBEX]])
        ->assertOk()
        ->assertJsonPath('message', 'LinkedIn pages saved.')
        ->assertJsonPath('data.selected_pages', [ACME, GLOBEX])
        ->assertJsonPath('data.pages.0.selected', false)
        ->assertJsonPath('data.pages.1.selected', true);

    app()->forgetScopedInstances();
    LinkedIn::clearResolvedInstance(LinkedInManager::class);

    expect(app(TokenStore::class)->get()?->selectedPages)->toBe([ACME, GLOBEX]);
    $this->actingAs($this->user)->getJson('/linkedin/connection')
        ->assertJsonPath('data.selected_pages', [ACME, GLOBEX]);
});

it('refuses an unknown page or an empty choice', function (mixed $pages, string $message) {
    connectWithPages();

    $this->actingAs($this->user)->putJson('/linkedin/connection/pages', ['pages' => $pages])
        ->assertStatus(422)
        ->assertJsonPath('message', $message);

    expect(app(TokenStore::class)->get()?->selectedPages)->toBe([MEMBER]);
})->with([
    'unknown' => [[ACME, 'urn:li:organization:999'], '[urn:li:organization:999] is not a LinkedIn page this account can post to.'],
    'empty' => [[], 'Choose at least one LinkedIn page.'],
    'missing' => [null, 'Choose at least one LinkedIn page.'],
]);

it('answers 422 when selecting pages while disconnected', function () {
    $this->actingAs($this->user)->putJson('/linkedin/connection/pages', ['pages' => [MEMBER]])->assertStatus(422);
});

it('saves the pages from the HTML form and redirects back', function () {
    connectWithPages();

    $this->actingAs($this->user)->from('/admin/integrations')
        ->put('/linkedin/connection/pages', ['pages' => [MEMBER, ACME]])
        ->assertRedirect('/admin/integrations')
        ->assertSessionHas('linkedin-autopost.flash', ['type' => 'success', 'message' => 'LinkedIn pages saved.']);

    expect(app(TokenStore::class)->get()?->selectedPages)->toBe([MEMBER, ACME]);
});

it('posts to every selected page as that page', function () {
    connectWithPages([ACME, GLOBEX]);
    Http::fake(['https://api.linkedin.com/rest/posts' => fn (Request $request) => Http::response(null, 201, [
        'x-restli-id' => 'urn:li:share:'.substr((string) $request['author'], -3),
    ])]);

    $post = livePost();
    $first = LinkedIn::share($post);

    expect($first->post_urn)->toBe('urn:li:share:111')
        ->and(LinkedInPost::query()->for($post)->pluck('author_urn', 'post_urn')->all())
        ->toBe(['urn:li:share:111' => ACME, 'urn:li:share:222' => GLOBEX])
        ->and(LinkedIn::wasPosted($post, ACME))->toBeTrue()
        ->and(LinkedIn::wasPosted($post, MEMBER))->toBeFalse();
    Http::assertSentCount(2);
});

it('keeps posting to the other pages when one fails, and records the failure', function () {
    connectWithPages([MEMBER, ACME]);
    Http::fake(['https://api.linkedin.com/rest/posts' => fn (Request $request) => $request['author'] === ACME
        ? Http::response(['message' => 'Not an admin'], 403)
        : Http::response(null, 201, ['x-restli-id' => 'urn:li:share:1'])]);

    $post = livePost();

    $this->actingAs($this->user)->postJson('/linkedin/share/post/'.$post->id)
        ->assertCreated()
        ->assertJsonPath('message', 'Shared on 1 of 2 LinkedIn pages.')
        ->assertJsonPath('data.post_urn', 'urn:li:share:1')
        ->assertJsonPath('data.posts.0', ['page' => MEMBER, 'status' => 'posted', 'post_urn' => 'urn:li:share:1', 'error' => null])
        ->assertJsonPath('data.posts.1', ['page' => ACME, 'status' => 'failed', 'post_urn' => null, 'error' => 'LinkedIn rejected the request: Not an admin']);
});

it('throws when every page failed', function () {
    connectWithPages([ACME, GLOBEX]);
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response(['message' => 'Down'], 500)]);

    $this->actingAs($this->user)->postJson('/linkedin/share/post/'.livePost()->id)
        ->assertStatus(502)
        ->assertJsonPath('message', 'LinkedIn rejected the request: Down');

    expect(LinkedInPost::query()->where('status', 'failed')->count())->toBe(2);
});

it('shares to the pages given with the request instead of the selected ones', function () {
    connectWithPages();
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:7'])]);

    $this->actingAs($this->user)->postJson('/linkedin/share/post/'.livePost()->id, ['pages' => [GLOBEX]])
        ->assertCreated()
        ->assertJsonPath('data.posts.0.page', GLOBEX);

    Http::assertSent(fn (Request $request) => $request['author'] === GLOBEX);

    $this->actingAs($this->user)->postJson('/linkedin/share/post/'.livePost()->id, ['pages' => ['urn:li:organization:999']])
        ->assertStatus(422);
});

it('retries only the pages that did not post yet', function () {
    Event::fake([ShareFailed::class]);
    connectWithPages([MEMBER, ACME]);
    $post = livePost();
    $acmeUp = false;
    Http::fake(['https://api.linkedin.com/rest/posts' => function (Request $request) use (&$acmeUp) {
        return $request['author'] === ACME && ! $acmeUp
            ? Http::response(['message' => 'Busy'], 503)
            : Http::response(null, 201, ['x-restli-id' => 'urn:li:share:'.substr((string) $request['author'], -3)]);
    }]);

    expect(fn () => (new ShareOnLinkedIn('post', $post->id))->handle(app(LinkedInManager::class)))
        ->toThrow(LinkedInRequestFailed::class, 'Busy');

    $acmeUp = true;
    (new ShareOnLinkedIn('post', $post->id))->handle(app(LinkedInManager::class));

    expect(LinkedInPost::query()->for($post)->posted()->pluck('author_urn')->all())->toBe([MEMBER, ACME]);
    Http::assertSentCount(3);
    Event::assertNotDispatched(ShareFailed::class);
});

it('names the page in a permanent failure of the job', function () {
    Event::fake([ShareFailed::class]);
    connectWithPages([ACME]);
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response(['message' => 'Not an admin'], 403)]);

    dispatch(new ShareOnLinkedIn('post', livePost()->id));

    expect(LinkedInPost::query()->sole()->error)->toBe('Acme: LinkedIn rejected the request: Not an admin');
    Event::assertDispatchedTimes(ShareFailed::class, 1);
});

it('lists the pages in linkedin:status and shares to a page with linkedin:share --page', function () {
    connectWithPages([MEMBER, ACME]);
    Http::fake([
        'https://www.linkedin.com/oauth/v2/introspectToken' => Http::response(['active' => true]),
        'https://api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abc']),
        'https://api.linkedin.com/rest/posts' => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:3']),
    ]);

    $this->artisan('linkedin:status')
        ->expectsOutputToContain('Acme')
        ->expectsOutputToContain(GLOBEX)
        ->assertSuccessful();

    $this->artisan('linkedin:share', ['model' => 'post', 'id' => livePost()->id, '--page' => [GLOBEX]])
        ->expectsOutputToContain('Shared on LinkedIn: urn:li:share:3')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.linkedin.com/rest/posts' && $request['author'] === GLOBEX);
});

it('shows the page checkboxes on the card when there is more than one page', function (string $theme) {
    config(['linkedin-autopost.ui.theme' => $theme]);
    Http::fake(['*' => Http::response('down', 503)]);
    connectWithPages([ACME]);

    $this->blade('<x-linkedin-autopost::connection />')
        ->assertSee('Post to')
        ->assertSee('Globex')
        ->assertSee(route('linkedin-autopost.pages.update'), false)
        ->assertSeeHtml('value="'.ACME.'"');
})->with(['tailwind', 'bootstrap']);

it('hides the page checkboxes when the member is the only page', function () {
    Http::fake(['*' => Http::response('down', 503)]);
    app(TokenStore::class)->put(new StoredConnection('member-token', MEMBER, name: 'Ada', connectedAt: CarbonImmutable::now()));

    $this->blade('<x-linkedin-autopost::connection />')->assertDontSee('Post to');
});

it('selects pages from the Livewire card', function () {
    Http::fake(['*' => Http::response('down', 503)]);
    connectWithPages();

    Livewire::test(ConnectionCard::class)
        ->assertSet('pages', [MEMBER])
        ->set('pages', [ACME, GLOBEX])
        ->call('selectPages')
        ->assertSee('LinkedIn pages saved.')
        ->set('pages', [])
        ->call('selectPages')
        ->assertSee('Choose at least one LinkedIn page.');

    expect(app(TokenStore::class)->get()?->selectedPages)->toBe([ACME, GLOBEX]);
})->skip(! class_exists(Livewire::class), 'Livewire is not installed.');

it('keeps working before the 1.2 migration has run', function () {
    Schema::table('linkedin_connections', fn (Blueprint $table) => $table->dropColumn(['pages', 'selected_pages']));
    Schema::table('linkedin_posts', function (Blueprint $table) {
        $table->dropIndex(['shareable_type', 'shareable_id', 'author_urn']);
        $table->dropColumn('author_urn');
    });
    Columns::flush();
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:1'])]);

    connectWithPages([ACME]);
    $post = livePost();

    // Without the columns nothing but the member's profile was stored, so that is where it posts.
    expect(LinkedIn::share($post)->post_urn)->toBe('urn:li:share:1')
        ->and(LinkedIn::wasPosted($post, MEMBER))->toBeTrue();
    Http::assertSent(fn (Request $request) => $request['author'] === MEMBER);

    // Put the columns back, so rolling the migrations back after the test works.
    Schema::table('linkedin_connections', function (Blueprint $table) {
        $table->json('pages')->nullable();
        $table->json('selected_pages')->nullable();
    });
    Schema::table('linkedin_posts', function (Blueprint $table) {
        $table->string('author_urn')->nullable();
        $table->index(['shareable_type', 'shareable_id', 'author_urn']);
    });
    Columns::flush();
});
