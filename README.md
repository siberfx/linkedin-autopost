# LinkedIn Autopost for Laravel

[![tests](https://github.com/siberfx/linkedin-autopost/actions/workflows/tests.yml/badge.svg)](https://github.com/siberfx/linkedin-autopost/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/siberfx/linkedin-autopost.svg)](https://packagist.org/packages/siberfx/linkedin-autopost)
[![PHP](https://img.shields.io/packagist/php-v/siberfx/linkedin-autopost.svg)](https://packagist.org/packages/siberfx/linkedin-autopost)
[![License](https://img.shields.io/packagist/l/siberfx/linkedin-autopost.svg)](LICENSE)

Connect one LinkedIn account to your Laravel app and share your models on LinkedIn — automatically
when they go live, or whenever you choose. Posts go through LinkedIn's Posts API as link posts with
a preview.

- One site-wide LinkedIn **member** account (the person who clicks Connect).
- Auto-post when a model becomes live, at most once, off until you switch it on.
- Ready-made connection card and share button (Tailwind or Bootstrap 5, Blade or Livewire), plus routes, JSON endpoints, Artisan commands and events if you prefer to build your own.
- Headless mode (`LINKEDIN_UI=false`) when you want the package without any UI.
- Optional Telegram, Slack and email messages on every post, failed share and expiring token, queued or sent at once.

Requires PHP 8.4+ and Laravel 12 or 13.

## Contents

- [Quick start](#quick-start)
- [1. Set up the LinkedIn app](#1-set-up-the-linkedin-app)
- [2. Install](#2-install)
- [3. Connect](#3-connect)
- [4. Make a model shareable](#4-make-a-model-shareable)
- [5. Share on demand](#5-share-on-demand)
- [6. Ready-made admin UI](#6-ready-made-admin-ui)
- [7. Worked example: your own modules](#7-worked-example-your-own-modules)
- [8. Build your admin UI on the endpoints](#8-build-your-admin-ui-on-the-endpoints)
- [9. Events](#9-events)
- [10. Commands and the 60-day token](#10-commands-and-the-60-day-token)
- [11. Telegram, Slack and email notifications](#11-telegram-slack-and-email-notifications)
- [12. Headless: no UI](#12-headless-no-ui)
- [13. Keeping the token somewhere else](#13-keeping-the-token-somewhere-else)
- [14. LinkedIn API versions](#14-linkedin-api-versions)
- [15. Testing your app](#15-testing-your-app)
- [16. Configuration reference](#16-configuration-reference)
- [17. Troubleshooting](#17-troubleshooting)

## Quick start

The shortest path from `composer require` to a first post, with the Tailwind UI:

```bash
composer require siberfx/linkedin-autopost
php artisan linkedin:install --theme=tailwind
php artisan migrate
```

```dotenv
LINKEDIN_CLIENT_ID=86abc…
LINKEDIN_CLIENT_SECRET=WPL_AP1.…
LINKEDIN_AUTOPOST=true
```

```php
// app/Providers/AppServiceProvider.php → boot()
Gate::define('manage-linkedin-autopost', fn (User $user) => $user->is_admin);
```

```php
// app/Models/Article.php
class Article extends Model implements ShareableOnLinkedIn
{
    use PostsToLinkedIn;

    public function isLiveForLinkedIn(): bool
    {
        return $this->status === 'published';
    }

    public function toLinkedInPost(): LinkPost
    {
        return LinkPost::make(route('blog.show', $this), $this->title);
    }
}
```

```blade
{{-- resources/views/admin/settings.blade.php --}}
<x-linkedin-autopost::connection />
```

Open the settings page, click **Connect LinkedIn**, run `php artisan queue:work`, and publish an
article: it appears on LinkedIn. Each step is explained below.

## 1. Set up the LinkedIn app

1. Create an app at <https://www.linkedin.com/developers/apps>.
2. **Products** tab: add **Share on LinkedIn** and **Sign In with LinkedIn using OpenID Connect**.
   Both are self-serve.
3. **Auth** tab → **Authorized redirect URLs for your app**: add your callback URL exactly, e.g.
   `https://example.com/linkedin/callback` — scheme, host (with or without `www`), path, no trailing slash.
   Add one per environment (`https://myapp.test/linkedin/callback` for local).
4. Copy the **Client ID** and **Primary Client Secret**.

> **"The redirect_uri does not match the registered value"** on LinkedIn's page means the URL your
> app sent is not in that list character for character. Run `php artisan route:list --name=linkedin-autopost.callback`
> to see the URL, or set `LINKEDIN_REDIRECT_URI` to the one you registered.

## 2. Install

```bash
composer require siberfx/linkedin-autopost
php artisan linkedin:install          # publishes config + migrations, asks tailwind or bootstrap
php artisan migrate
```

`linkedin:install` options:

```bash
php artisan linkedin:install --theme=bootstrap   # no question asked
php artisan linkedin:install --headless          # no UI at all, see section 12
```

It publishes `config/linkedin-autopost.php` and the two migrations, writes `LINKEDIN_UI_THEME` (or
`LINKEDIN_UI=false`) to `.env` when it is not there yet, and prints the redirect URI to register and
the gate to define. To publish pieces by hand:

```bash
php artisan vendor:publish --tag=linkedin-autopost-config
php artisan vendor:publish --tag=linkedin-autopost-migrations
php artisan vendor:publish --tag=linkedin-autopost-views    # only when the UI is enabled
php artisan vendor:publish --tag=linkedin-autopost-stubs
```

> **Using UUID or ULID keys?** Edit the published `create_linkedin_posts_table` migration: replace
> `morphs('shareable')` with `uuidMorphs('shareable')` / `ulidMorphs('shareable')` before migrating.

`.env`:

```dotenv
LINKEDIN_CLIENT_ID=...
LINKEDIN_CLIENT_SECRET=...
# LINKEDIN_AUTOPOST=true   # switch on when ready
LINKEDIN_UI_THEME=tailwind     # or bootstrap
# LINKEDIN_REDIRECT_URI=https://example.com/linkedin/callback   # only if it differs from the route
# LINKEDIN_VISIBILITY=PUBLIC   # or CONNECTIONS
```

**Required: say who may manage LinkedIn.** Everything — connecting, disconnecting, seeing the
connection (including the member's email) and sharing — is guarded by the
`manage-linkedin-autopost` gate. The package defines it to **deny everyone** until your app defines
it, for example in `AppServiceProvider::boot()`:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

Gate::define('manage-linkedin-autopost', fn (User $user) => $user->is_admin);
```

Any rule works — a role package, a policy-style check, a list of emails:

```php
// spatie/laravel-permission
Gate::define('manage-linkedin-autopost', fn (User $user) => $user->hasRole('marketing'));

// a fixed list
Gate::define('manage-linkedin-autopost', fn (User $user) => in_array($user->email, config('app.admins'), true));
```

Your definition wins whatever the provider order. The routes sit behind
`['web', 'auth', 'can:manage-linkedin-autopost']`; the Livewire actions check the same gate, and the
components show the connection read-only and the share button disabled for people it refuses.
Change the middleware, the URL prefix or the page you come back to in `config/linkedin-autopost.php`:

```php
'routes' => [
    'enabled' => true,
    'prefix' => 'admin/linkedin',        // → /admin/linkedin/redirect, /admin/linkedin/callback …
    'name' => 'linkedin-autopost.',
    'middleware' => ['web', 'auth:admin', 'can:manage-linkedin-autopost'],
    'after_connect' => '/admin/settings',
],
```

Changing the prefix changes the callback URL: register the new one on your LinkedIn app.

## 3. Connect

Send an admin to `route('linkedin-autopost.redirect')`. After LinkedIn's consent screen they land on
`after_connect` with one of these query values:

| `?linkedin=` | Meaning |
|---|---|
| `connected` | Done. |
| `cancelled` | The person cancelled on LinkedIn. |
| `error&reason=…` | LinkedIn refused, e.g. `Scope "profile" is not authorized for your application` (add the OpenID Connect product). |
| `exchange_failed` | The code could not be exchanged; the reason is in your log. Usually a redirect URL mismatch. |
| `invalid_state` | The login attempt expired or was replayed. Connect again. |
| `missing_code` | LinkedIn came back without an authorization code. Connect again. |
| `not_configured` | Client id or secret missing. |

The connection card and `<x-linkedin-autopost::flash />` turn these into messages for you. With your
own page, a plain link and a message are enough:

```blade
<a href="{{ route('linkedin-autopost.redirect') }}" class="btn btn-primary">Connect LinkedIn</a>

@if (request('linkedin') === 'connected')
    <div class="alert alert-success">LinkedIn connected.</div>
@elseif (request('linkedin'))
    <div class="alert alert-danger">
        LinkedIn connection failed ({{ request('linkedin') }}) {{ request('reason') }}
    </div>
@endif
```

Check the connection anywhere:

```php
use Siberfx\LinkedInAutopost\Facades\LinkedIn;

if (LinkedIn::isConnected()) {
    $connection = LinkedIn::connection();

    $connection->name;        // "Ada Lovelace"
    $connection->email;       // "ada@example.com"
    $connection->status;      // active | expired | revoked | unknown
    $connection->expiresAt;   // CarbonImmutable|null
    $connection->daysLeft();  // 53
    $connection->toArray();   // everything above as an array, never the token
}
```

`connection()` asks LinkedIn (token introspection and userinfo) at most once every
`status_cache_ttl` seconds (300 by default) and serves the cached status in between.
`isConnected()` only checks that a token is stored and never calls LinkedIn.

## 4. Make a model shareable

```php
use Illuminate\Support\Str;
use Siberfx\LinkedInAutopost\Concerns\PostsToLinkedIn;
use Siberfx\LinkedInAutopost\Contracts\ShareableOnLinkedIn;
use Siberfx\LinkedInAutopost\Posts\LinkPost;

class Article extends Model implements ShareableOnLinkedIn
{
    use PostsToLinkedIn;

    public function isLiveForLinkedIn(): bool
    {
        return $this->status === 'published';
    }

    public function toLinkedInPost(): LinkPost
    {
        return LinkPost::make(url: route('blog.show', $this), title: $this->title)
            ->description(Str::limit(strip_tags($this->body), 250))
            ->commentary("New on the blog: {title}\n\n{url}");
    }
}
```

The two methods:

- **`isLiveForLinkedIn()`** — whether the public can see the model right now. It decides when
  auto-post fires (the save on which it turns from `false` to `true`) and whether a manual share is
  allowed. Use any rule: an enum, a boolean, dates.
- **`toLinkedInPost()`** — what to post. `LinkPost::make($url, $title)` is the link and its preview
  title; `description()` is the preview text below it; `commentary()` is the post text above the
  preview, where `{title}` and `{url}` are filled in (default: `"{title}\n\n{url}"`).

| `LinkPost` | Limit | Default |
|---|---|---|
| `make(string $url, string $title)` | title cut to 200 characters | — |
| `->description(string)` | cut to 256 characters | empty |
| `->commentary(string $template)` | — | `"{title}\n\n{url}"` |

`LinkPost` is immutable: each method returns a copy.

More `isLiveForLinkedIn()` rules:

```php
// An enum
public function isLiveForLinkedIn(): bool
{
    return $this->status === ArticleStatus::Published;
}

// A boolean plus a publish date (pair with "Scheduled publishing" in section 7)
public function isLiveForLinkedIn(): bool
{
    return $this->is_published && $this->published_at?->isPast();
}

// Only some records go to LinkedIn
public function isLiveForLinkedIn(): bool
{
    return $this->status === 'published' && $this->share_on_linkedin;
}
```

More `toLinkedInPost()` styles:

```php
// Product launch with a price line
return LinkPost::make(route('shop.products.show', $this), $this->name)
    ->description($this->tagline)
    ->commentary("Just launched: {title} — €{$this->price}\n\n{url}");

// Events with a date in the text
return LinkPost::make(route('events.show', $this), $this->title)
    ->description($this->venue)
    ->commentary("Join us on {$this->starts_at->isoFormat('D MMMM')}: {title}\n\nTickets: {url}");

// Translated text, per model locale
return LinkPost::make(route('blog.show', $this), $this->title)
    ->commentary(__('linkedin.new_article', locale: $this->locale)."\n\n{url}");
```

With `LINKEDIN_AUTOPOST=true`, an `Article` is queued for LinkedIn when it is **created published**
or **changes from draft to published** — once. Editing a published article does not post again.
Seeders and Artisan commands do not auto-post (set `autopost.in_console` to allow it). Auto-post
reacts to model saves through Eloquent events, so mass `update()` queries and `saveQuietly()` do not
auto-post.

```php
Article::create(['title' => 'Hello', 'status' => 'published']);  // queued
$draft->update(['status' => 'published']);                        // queued (draft → published)
$published->update(['title' => 'Typo fixed']);                    // not queued: it was already live
Article::where('id', 5)->update(['status' => 'published']);       // not queued: no model events
$article->saveQuietly();                                          // not queued: no model events
```

Run a queue worker; the job is dispatched after the database transaction commits, is unique per
model while it waits, and retries 3 times when LinkedIn is busy or unreachable (429, 5xx, network).
A request LinkedIn refuses outright (401, 403, 422 …) or an invalid post fails at once, without
retries. With the `sync` queue the share runs inside the request that saved the model, so a
LinkedIn failure surfaces there — use a real queue.

```php
'autopost' => [
    'enabled' => (bool) env('LINKEDIN_AUTOPOST', false),
    'in_console' => false,
    'queue_connection' => 'redis',   // null = your default connection
    'queue' => 'linkedin',           // null = the default queue
],
'job' => [
    'tries' => 3,
    'backoff' => [60, 300],          // seconds before the 2nd and 3rd attempt
],
```

```bash
php artisan queue:work redis --queue=linkedin,default
```

URLs are normalised for you: a non-ASCII path such as `/blog/çalışma` is percent-encoded and an
internationalised host (`şirket.com.tr`) is converted to punycode when `ext-intl` is installed.
`LinkPost::make()` throws `InvalidArgumentException` for a relative or non-http(s) URL.

Write the commentary as plain text: characters LinkedIn reserves for mentions and hashtags
(`# @ [ ] ( ) _ *` …) are escaped for you, so they appear exactly as written. LinkedIn does not
fetch the link's own preview; the title and description you set are what it shows.

## 5. Share on demand

```php
use Siberfx\LinkedInAutopost\Facades\LinkedIn;

LinkedIn::share($article);           // now; returns the LinkedInPost record (re-sharing is allowed)
LinkedIn::queue($article);           // in the background
LinkedIn::wasPosted($article);       // bool, same as $article->wasPostedToLinkedIn()
$article->wasPostedToLinkedIn();     // bool
$article->linkedInPosts;             // history: post_urn, status, trigger, error, posted_at
```

`share()` throws `NotConnected`, `NotShareable` (not live), `LinkedInRequestFailed` (LinkedIn's
message) or `InvalidArgumentException` (your `toLinkedInPost()` built an invalid URL). A post LinkedIn
accepted without returning its id is recorded as posted with a `null` `post_urn`.

A complete controller action with every outcome handled:

```php
use Siberfx\LinkedInAutopost\Exceptions\LinkedInRequestFailed;
use Siberfx\LinkedInAutopost\Exceptions\NotConnected;
use Siberfx\LinkedInAutopost\Exceptions\NotShareable;
use Siberfx\LinkedInAutopost\Facades\LinkedIn;

class ArticleLinkedInController
{
    public function __invoke(Article $article)
    {
        Gate::authorize('manage-linkedin-autopost');

        try {
            $post = LinkedIn::share($article);
        } catch (NotConnected) {
            return back()->with('error', 'Connect LinkedIn first.');
        } catch (NotShareable) {
            return back()->with('error', 'Publish the article before sharing it.');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());     // e.g. a relative URL
        } catch (LinkedInRequestFailed $e) {
            if ($e->isUnauthorized()) {
                return back()->with('error', 'The LinkedIn token expired. Connect again.');
            }

            return back()->with('error', $e->getMessage());     // "LinkedIn rejected the request: …"
        }

        return back()->with('success', 'Shared on LinkedIn: '.$post->post_urn);
    }
}
```

`LinkedInRequestFailed` carries the HTTP status (`$e->status`, `0` for a network error),
`isUnauthorized()` (401) and `isPermanent()` (a 4xx other than 429: retrying cannot help).

Share only once, from your own code:

```php
if (! LinkedIn::wasPosted($article)) {
    LinkedIn::queue($article);
}
```

Read the history:

```php
use Siberfx\LinkedInAutopost\Models\LinkedInPost;

$article->linkedInPosts()->latest('id')->first();      // last attempt

LinkedInPost::query()->for($article)->posted()->get();  // successful posts of one model

LinkedInPost::query()                                   // every failure this week
    ->where('status', LinkedInPost::STATUS_FAILED)
    ->where('created_at', '>=', now()->subWeek())
    ->with('shareable')
    ->get();
```

| Column | Values |
|---|---|
| `status` | `posted`, `failed` |
| `trigger` | `auto` (auto-post), `manual` (button, endpoint, `share()`), `console` (`linkedin:share`) |
| `post_urn` | e.g. `urn:li:share:7212345678901234567`; open it at `https://www.linkedin.com/feed/update/{post_urn}/` |
| `error` | LinkedIn's message for a failed automatic share |

## 6. Ready-made admin UI

Pick the theme your admin uses (`LINKEDIN_UI_THEME=tailwind` or `bootstrap`) and drop in the components.
Building your own UI, or none at all? See [headless mode](#12-headless-no-ui).

### Settings page: the connection card

```blade
{{-- Blade, no JavaScript needed --}}
<x-linkedin-autopost::connection />

{{-- or Livewire (no page reload) --}}
<livewire:linkedin-autopost.connection />
```

Not connected, it shows **Connect LinkedIn**. Connected, it shows the member, when the token was
issued and until when it is valid, a status badge (amber in the last 14 days, red when expired or
revoked), **Reconnect** and **Disconnect**, plus the result of the last connect. People the
`manage-linkedin-autopost` gate refuses see the status only, without the buttons.

A full settings page (Bootstrap layout shown; the Tailwind one is the same with your classes):

```blade
@extends('layouts.admin')

@section('content')
    <h1 class="h3 mb-4">Integrations</h1>

    <x-linkedin-autopost::flash />

    <div class="row">
        <div class="col-lg-6">
            <x-linkedin-autopost::connection />
        </div>
    </div>
@endsection
```

Remember to set `routes.after_connect` to this page's URL so the browser returns here after LinkedIn.

### Lists and edit pages: the share button

```blade
@foreach ($articles as $article)
    <tr>
        <td>{{ $article->title }}</td>
        <td><x-linkedin-autopost::share-button :model="$article" size="sm" /></td>
    </tr>
@endforeach

<x-linkedin-autopost::flash />   {{-- shows "Shared on LinkedIn." after the form posts back --}}
```

Livewire: `<livewire:linkedin-autopost.share-button :model="$article" :wire:key="'li-'.$article->id" />`
(listen for the `linkedin-autopost-shared` browser event to react).

Props: `label`, `size` (`sm` / `md`), `:confirm="false"` to skip the confirmation. The button reads
**Share again** once something was posted, shows the last date as its tooltip, and is disabled,
with the reason, while the `manage-linkedin-autopost` gate refuses the viewer, LinkedIn is not
connected or the model is not live. It posts to a **signed** URL, so it works without a morph map.

More share-button samples:

```blade
{{-- Edit page: a bigger button with your own label, no confirmation --}}
<x-linkedin-autopost::share-button :model="$article" label="Post to LinkedIn" size="md" :confirm="false" />

{{-- Livewire table with a toast when a post goes out (Alpine, ships with Livewire) --}}
<div x-data="{ toast: null }"
     x-on:linkedin-autopost-shared.window="toast = 'Posted: ' + ($event.detail.urn ?? 'done'); setTimeout(() => toast = null, 4000)">
    @foreach ($articles as $article)
        <livewire:linkedin-autopost.share-button :model="$article" size="sm" :wire:key="'li-'.$article->id" />
    @endforeach

    <div x-show="toast" x-text="toast" class="toast-message" x-cloak></div>
</div>
```

```php
// Or react inside one of your own Livewire components
use Livewire\Attributes\On;

#[On('linkedin-autopost-shared')]
public function refreshHistory(?string $urn = null): void
{
    $this->history = $this->article->linkedInPosts()->latest('id')->get();
}
```

Things to know:

- **Signed share URLs do not expire.** They are protected by your auth middleware, the gate and CSRF.
  Behind a TLS-terminating proxy or load balancer, configure `TrustProxies` so the signature (which
  covers the scheme and host) validates.
- **Guard the page as well.** The Livewire actions check the `manage-linkedin-autopost` gate, but
  they run through Livewire's own endpoint, not the package routes, so any extra middleware you add
  to `routes.middleware` does not apply to them. Put the components on pages restricted to the same
  people.
- **Show form errors with the flash component.** The Blade (form-based) share button flashes its
  success and failure message to the session. Put `<x-linkedin-autopost::flash />` on the page, as in
  the example above, or the person never sees why a share failed.
- **Boolean props need the colon.** Write `:confirm="false"`. A plain `confirm="false"` is the string
  `"false"`, which is truthy, so the confirmation would stay on.
- **CSS versions.** The Tailwind views use Tailwind 3.4+ or v4 utilities (for example `size-*`). The
  Bootstrap views need Bootstrap 5.3 (`text-bg-*`, `bg-primary-subtle`, `text-body-secondary`).

The flash message is stored in the session under `linkedin-autopost.flash` as
`['type' => 'success'|'error', 'message' => '…']`, if you prefer to render it in your own layout:

```blade
@if ($flash = session('linkedin-autopost.flash'))
    <div @class(['alert', 'alert-success' => $flash['type'] === 'success', 'alert-danger' => $flash['type'] === 'error'])>
        {{ $flash['message'] }}
    </div>
@endif
```

### Tailwind

Tailwind only generates classes it finds. Add the package views to your sources:

```css
/* Tailwind v4, resources/css/app.css */
@source '../../vendor/siberfx/linkedin-autopost/resources/views/tailwind';
```

```js
// Tailwind v3, tailwind.config.js
content: ['./vendor/siberfx/linkedin-autopost/resources/views/tailwind/**/*.blade.php', /* ... */],
```

Bootstrap 5.3 needs nothing extra.

### Restyling, or a theme of your own

```bash
php artisan vendor:publish --tag=linkedin-autopost-views
```

Edit `resources/views/vendor/linkedin-autopost/{tailwind,bootstrap}/`, or copy one folder to e.g.
`daisyui/` and set `LINKEDIN_UI_THEME=daisyui`. A theme is three views: `connection`, `share-button`, `flash`.

```bash
cp -r resources/views/vendor/linkedin-autopost/tailwind resources/views/vendor/linkedin-autopost/daisyui
```

```dotenv
LINKEDIN_UI_THEME=daisyui
```

## 7. Worked example: your own modules

`php artisan vendor:publish --tag=linkedin-autopost-stubs` copies a commented example model to
`stubs/linkedin-autopost/ShareableModel.stub`. Two typical modules:

```php
// A blog: posted when published.
class Article extends Model implements ShareableOnLinkedIn
{
    use PostsToLinkedIn;

    public function isLiveForLinkedIn(): bool
    {
        return $this->status === 'published';
    }

    public function toLinkedInPost(): LinkPost
    {
        return LinkPost::make(route('blog.show', $this), $this->title)
            ->description(Str::limit(strip_tags($this->body), 250));
    }
}

// Job vacancies: posted when active and still open.
class JobVacancy extends Model implements ShareableOnLinkedIn
{
    use PostsToLinkedIn;

    protected $casts = ['is_active' => 'boolean', 'closing_date' => 'date'];

    public function isLiveForLinkedIn(): bool
    {
        return $this->is_active && ($this->closing_date === null || $this->closing_date->endOfDay()->isFuture());
    }

    public function toLinkedInPost(): LinkPost
    {
        return LinkPost::make(route('careers.show', $this), "We're hiring: {$this->title}")
            ->description("{$this->location} · {$this->type}")
            ->commentary("We're hiring: {title}\n{$this->location} · {$this->type}\n\nApply: {url}");
    }
}
```

Give each module a morph-map alias so the share endpoint, `linkedin:share` and the history use short,
stable names instead of class names:

```php
// AppServiceProvider::boot()
use Illuminate\Database\Eloquent\Relations\Relation;

Relation::enforceMorphMap([
    'article' => Article::class,
    'vacancy' => JobVacancy::class,
    'user' => User::class,           // enforceMorphMap needs every morphed model listed
]);
```

### Scheduled publishing

Auto-post reacts to saves. A model that becomes live only because time passes (a future
`published_at`, an opening date) is never saved at that moment, so nothing posts it. Schedule a
small job that queues what has become live and was not posted yet:

```php
use Illuminate\Support\Facades\Schedule;
use Siberfx\LinkedInAutopost\Facades\LinkedIn;

Schedule::call(function () {
    Article::query()->whereNotNull('published_at')->where('published_at', '<=', now())
        ->whereDoesntHave('linkedInPosts', fn ($q) => $q->where('status', 'posted'))
        ->each(fn (Article $article) => LinkedIn::queue($article));
})->everyFiveMinutes();
```

The same idea as an invokable class, when you prefer it out of `routes/console.php`:

```php
// app/Console/QueueDueLinkedInPosts.php
final class QueueDueLinkedInPosts
{
    public function __invoke(): void
    {
        if (! LinkedIn::isConnected()) {
            return;
        }

        JobVacancy::query()
            ->where('is_active', true)
            ->where('opens_at', '<=', now())
            ->whereDoesntHave('linkedInPosts', fn ($q) => $q->where('status', 'posted'))
            ->lazyById()
            ->each(fn (JobVacancy $vacancy) => LinkedIn::queue($vacancy));
    }
}

// routes/console.php
Schedule::call(new QueueDueLinkedInPosts)->everyFiveMinutes()->withoutOverlapping();
```

The queued job is unique per model and checks again that nothing was posted, so overlapping runs
cannot post twice.

React when a post goes out, e.g. to log the link:

```php
Event::listen(Shared::class, function (Shared $event) {
    logger()->info('Posted to LinkedIn', ['urn' => $event->post->post_urn]);
});
```

## 8. Build your admin UI on the endpoints

| Method | URL | Returns |
|---|---|---|
| GET | `/linkedin/redirect` | Redirect to LinkedIn's consent screen |
| GET | `/linkedin/callback` | Redirect to `after_connect` with `?linkedin=…` |
| GET | `/linkedin/connection` | `{"data": {connected, status, name, email, picture, author_urn, scopes, connected_at, expires_at, days_left}}` |
| DELETE | `/linkedin/connection` | Revokes and forgets the token; `{"message", "revoked", "data"}` |
| POST | `/linkedin/share/{type}/{id}` | `201 {"data": {post_urn, posted_at}}` (`post_urn` is `null` when LinkedIn accepted the post without returning its id), `422` not connected / not live / invalid post URL, `502` LinkedIn refused, `404` unknown |

Every endpoint answers `403` to people the `manage-linkedin-autopost` gate refuses. Route names:
`linkedin-autopost.redirect`, `.callback`, `.connection.show`, `.connection.destroy`, `.share`, `.share.signed`.

`status` is `active`, `expired`, `revoked` or `unknown` (LinkedIn unreachable). The share endpoint
accepts **morph-map aliases only**:

```php
Relation::enforceMorphMap(['article' => Article::class]);
```

Without a morph map, call `LinkedIn::share()` from your own controller.

Sample responses:

```json
// GET /linkedin/connection
{
  "data": {
    "connected": true,
    "status": "active",
    "name": "Ada Lovelace",
    "email": "ada@example.com",
    "picture": "https://media.licdn.com/…",
    "author_urn": "urn:li:person:abc123",
    "scopes": ["openid", "profile", "email", "w_member_social"],
    "connected_at": "2026-09-01T10:00:00+00:00",
    "expires_at": "2026-10-31T10:00:00+00:00",
    "days_left": 29
  }
}

// POST /linkedin/share/article/42 → 201
{ "message": "Shared on LinkedIn.", "data": { "post_urn": "urn:li:share:7212345678901234567", "posted_at": "2026-10-02T09:15:00+00:00" } }

// POST /linkedin/share/article/43 → 422
{ "message": "This item is not live, so it cannot be shared on LinkedIn." }
```

The routes use the `web` middleware, so a browser client sends the session cookie and the CSRF token.
With `fetch`:

```html
<meta name="csrf-token" content="{{ csrf_token() }}">

<script>
const headers = {
    'Accept': 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
};

async function linkedInStatus() {
    const { data } = await (await fetch('/linkedin/connection', { headers })).json();
    return data; // { connected, status, name, days_left, … }
}

async function shareOnLinkedIn(type, id) {
    const response = await fetch(`/linkedin/share/${type}/${id}`, { method: 'POST', headers });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message);
    return body.data.post_urn;
}

async function disconnectLinkedIn() {
    await fetch('/linkedin/connection', { method: 'DELETE', headers });
}
</script>
```

With axios (Laravel's default `bootstrap.js` sets the CSRF header for you):

```js
const { data } = await axios.get('/linkedin/connection');
await axios.post('/linkedin/share/article/42');
await axios.delete('/linkedin/connection');
```

A Vue/Inertia or React admin uses the same three calls; for a token-authenticated SPA or mobile
client, see [headless mode](#12-headless-no-ui) to put the routes behind `auth:sanctum`.

## 9. Events

| Event | When |
|---|---|
| `Connected` | After a successful connect (`$event->connection`). |
| `Disconnected` | After disconnect (`$event->revoked`). |
| `Shared` | After every successful post (`$event->shareable`, `$event->post`). |
| `ShareFailed` | An automatic share gave up after its last attempt (`$event->shareableType`, `$event->shareableId`, `$event->exception`). |
| `TokenExpiringSoon` | From `linkedin:check-token` (`$event->connection`, `$event->daysLeft`). |

All live in `Siberfx\LinkedInAutopost\Events`.

```php
Event::listen(ShareFailed::class, fn (ShareFailed $e) =>
    Notification::route('mail', 'admin@example.com')->notify(new LinkedInShareFailed($e)));
```

More listeners:

```php
use Siberfx\LinkedInAutopost\Events\Connected;
use Siberfx\LinkedInAutopost\Events\Disconnected;
use Siberfx\LinkedInAutopost\Events\Shared;
use Siberfx\LinkedInAutopost\Events\TokenExpiringSoon;

// Keep the LinkedIn post link on the model itself
Event::listen(Shared::class, function (Shared $event) {
    if ($event->shareable instanceof Article && $event->post->post_urn !== null) {
        $event->shareable->updateQuietly([
            'linkedin_url' => "https://www.linkedin.com/feed/update/{$event->post->post_urn}/",
        ]);
    }
});

// Audit log
Event::listen(Connected::class, fn (Connected $e) =>
    activity()->log("LinkedIn connected as {$e->connection->name}"));
Event::listen(Disconnected::class, fn (Disconnected $e) =>
    activity()->log('LinkedIn disconnected'.($e->revoked ? '' : ' (revocation not confirmed)')));

// Email the admins before the token runs out
Event::listen(TokenExpiringSoon::class, fn (TokenExpiringSoon $e) =>
    Mail::to(config('app.admin_email'))->send(new LinkedInTokenExpiring($e->daysLeft)));
```

Use `updateQuietly()` in a `Shared` listener: a normal `save()` fires the model's events again (it
will not post twice, but there is no reason to run the check).

## 10. Commands and the 60-day token

```bash
php artisan linkedin:status
php artisan linkedin:share article 42 [--force]
php artisan linkedin:disconnect [--force]
php artisan linkedin:check-token [--days=7]
php artisan linkedin:test-notification [--channel=slack]
```

| Command | Does |
|---|---|
| `linkedin:status` | Table of the member, author URN, status, scopes, issue and expiry dates, days left. |
| `linkedin:share {model} {id}` | Shares now (`trigger` = `console`). `model` is a morph alias or a class name. Refuses an already posted model unless `--force`. |
| `linkedin:disconnect` | Revokes and forgets the token; asks first unless `--force`. |
| `linkedin:check-token` | Fires `TokenExpiringSoon` when fewer than `--days` (default `expiry_warning_days`) remain or the token is expired/revoked. |
| `linkedin:test-notification` | Sends a test message to the enabled Telegram/Slack/email/custom channels. |
| `linkedin:install` | See [Install](#2-install). |

```bash
$ php artisan linkedin:share article 42
   INFO  Shared on LinkedIn: urn:li:share:7212345678901234567.

$ php artisan linkedin:share "App\Models\JobVacancy" 7 --force
```

LinkedIn member tokens last **60 days**, and self-serve apps get **no refresh token** — someone has to
click Connect again before it runs out. Schedule the check and listen for `TokenExpiringSoon`:

```php
Schedule::command('linkedin:check-token')->daily();
```

With [notifications](#11-telegram-slack-and-email-notifications) switched on, that daily check also sends
the expiry warning to Telegram, Slack or email — no listener needed.

## 11. Telegram, Slack and email notifications

Get a message when a post goes out, when an automatic share gives up, and when the token is about
to expire. Everything is off by default; each switch is in the `notifications` section of the config:

```dotenv
LINKEDIN_NOTIFY=true                 # master switch

LINKEDIN_NOTIFY_TELEGRAM=true
LINKEDIN_TELEGRAM_BOT_TOKEN=123456:ABC…   # from @BotFather
LINKEDIN_TELEGRAM_CHAT_ID=-1001234567890  # user, group or @channel; add the bot to it first
# LINKEDIN_TELEGRAM_THREAD_ID=42          # forum topic, optional

LINKEDIN_NOTIFY_SLACK=true
LINKEDIN_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/…

LINKEDIN_NOTIFY_MAIL=true
LINKEDIN_NOTIFY_MAIL_TO=ops@example.com,marketing@example.com
# LINKEDIN_NOTIFY_MAILER=postmark          # a mailer from config/mail.php; default mailer when unset

# LINKEDIN_NOTIFY_QUEUED=false             # send at once instead of from a queued job
```

Turn on any combination of channels; each one gets every message. Choose which events send a message:

```php
'notifications' => [
    'events' => ['shared' => true, 'failed' => true, 'token_expiring' => true],
    'queued' => true,
    'queue_connection' => null,
    'queue' => null,
    // …
],
```

Then check the settings:

```bash
php artisan linkedin:test-notification [--channel=telegram]
```

```
   INFO  telegram: sent.
   INFO  slack: sent.
   INFO  mail: sent.
```

The test command always sends at once (not queued), even while `LINKEDIN_NOTIFY` is off, so you can
check the settings before switching notifications on.

### Setting up Telegram

1. Talk to [@BotFather](https://t.me/BotFather), send `/newbot`, and copy the token
   (`123456789:AAE…`) into `LINKEDIN_TELEGRAM_BOT_TOKEN`.
2. Add the bot to the group or channel that should receive the messages (in a channel, as an admin
   allowed to post). For messages to yourself, open the bot and press **Start**.
3. Find the chat id: send any message in that chat, then open
   `https://api.telegram.org/bot<token>/getUpdates` and read `message.chat.id` — negative for groups
   (`-100…` for supergroups and channels). A public channel can also use `@channelname`.
4. Groups with **topics**: put the topic's `message_thread_id` in `LINKEDIN_TELEGRAM_THREAD_ID`.

### Setting up Slack

1. Create an app at <https://api.slack.com/apps> → **From scratch**, pick the workspace.
2. **Incoming Webhooks** → switch on → **Add New Webhook to Workspace** → choose the channel.
3. Copy the URL (`https://hooks.slack.com/services/T…/B…/…`) into `LINKEDIN_SLACK_WEBHOOK_URL`.

### Setting up email

Email goes through your app's own mailer, so configure `config/mail.php` (`MAIL_MAILER`,
`MAIL_FROM_ADDRESS`, …) as for any Laravel mail; the package adds nothing of its own.

```dotenv
LINKEDIN_NOTIFY_MAIL=true
LINKEDIN_NOTIFY_MAIL_TO=ops@example.com,marketing@example.com
```

- `to` is one address or several, comma separated (or an array in the config file). Invalid
  addresses are ignored; with no valid address the channel is skipped.
- `mailer` picks a mailer from `config/mail.php` (e.g. `ses`, `postmark`, `log`); unset uses the default.
- The subject is the headline (`✅ Shared on LinkedIn: …`); the body is a short HTML email with the
  lines below it and links made clickable. It needs no views, so it works in headless mode too.
- In local development, `MAIL_MAILER=log` writes the email to `storage/logs/laravel.log`.

The email is the `Siberfx\LinkedInAutopost\Notifications\NotificationMail` mailable, so your tests can
assert it:

```php
Mail::fake();

LinkedIn::share($article);

Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo('ops@example.com'));
```

Need a different recipient list per environment, or your own template? Leave the `mail` channel off
and register your own mail channel (see [A channel of your own](#a-channel-of-your-own)).

### What the messages look like

```
✅ Shared on LinkedIn: We're hiring: Senior Laravel Developer
https://example.com/careers/senior-laravel-developer
Post: https://www.linkedin.com/feed/update/urn:li:share:7212345678901234567/
Trigger: auto
```

```
❌ LinkedIn share failed
Model: article #42
Error: LinkedIn rejected the request: Content is a duplicate
```

```
⚠️ The LinkedIn token expires in 5 days
Account: Ada Lovelace
Connect again to keep posting.
```

The headline is bold (HTML in Telegram and email, mrkdwn in Slack); titles are escaped, so `<`, `>`
and `&` in your content cannot break the formatting. In email the headline is also the subject.

### Common setups

```dotenv
# Only Slack, only failures and expiry (no message for every post)
LINKEDIN_NOTIFY=true
LINKEDIN_NOTIFY_SLACK=true
LINKEDIN_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/…
```

```php
'events' => ['shared' => false, 'failed' => true, 'token_expiring' => true],
```

```dotenv
# Telegram in production only: leave LINKEDIN_NOTIFY unset in .env.local / staging
LINKEDIN_NOTIFY=true
LINKEDIN_NOTIFY_TELEGRAM=true
```

```dotenv
# Email the team about failures only, Slack for everything
LINKEDIN_NOTIFY=true
LINKEDIN_NOTIFY_SLACK=true
LINKEDIN_NOTIFY_MAIL=true
LINKEDIN_NOTIFY_MAIL_TO=dev-team@example.com
```

The event switches apply to every channel. To route events to different channels, keep only Slack
on here and send the failure email from a `ShareFailed` listener (section 9).

### Queued or immediate

Notifications are **queued by default** (`queued` = `true`, `LINKEDIN_NOTIFY_QUEUED`):

| | `queued` = `true` (default) | `queued` = `false` |
|---|---|---|
| How | One `SendNotification` job per channel | Sent at once, one channel after the other |
| Where | Your queue worker | Inside the request, job or command that shared |
| Retries | `tries` times with `backoff` per channel | None |
| A failure | Retried, then in `failed_jobs` | Reported to your exception handler |
| Needs | A running queue worker (or `QUEUE_CONNECTION=sync`) | Nothing |

```php
// Queued, on a dedicated queue with more patience
'queued' => true,
'queue_connection' => 'redis',
'queue' => 'notifications',
'tries' => 5,
'backoff' => [30, 120, 600],
```

```bash
php artisan queue:work redis --queue=notifications,default
```

```dotenv
# No queue worker at all (small sites, cron-only hosting)
LINKEDIN_NOTIFY_QUEUED=false
```

Either way a failing channel never fails the share or blocks the other channels. Auto-posts already
run in a queued job, so with `queued` = `false` their notifications are sent from that job; a
manual share from the button waits for Telegram, Slack and the mail server before it answers (each
HTTP call is limited by `http.timeout`). On the `sync` queue connection the queued jobs also run at
once, and errors are reported the same way.

The token-expiry message needs the scheduled `linkedin:check-token` from the previous section.
Turning a switch off also drops messages that are already waiting in the queue.

### A channel of your own

To add a channel (Teams, Discord, a webhook of your own…), implement
`Siberfx\LinkedInAutopost\Contracts\NotificationChannel` and list it; the container builds it with
its config array as `$config`:

```php
'channels' => [
    // telegram, slack…
    'discord' => [
        'enabled' => (bool) env('LINKEDIN_NOTIFY_DISCORD', false),
        'class' => App\Notifications\DiscordChannel::class,
        'webhook_url' => env('DISCORD_WEBHOOK_URL'),
    ],
],
```

```php
namespace App\Notifications;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Siberfx\LinkedInAutopost\Contracts\NotificationChannel;
use Siberfx\LinkedInAutopost\Exceptions\NotificationFailed;
use Siberfx\LinkedInAutopost\Notifications\Message;

final class DiscordChannel implements NotificationChannel
{
    public function __construct(private readonly array $config = []) {}

    public function configured(): bool
    {
        return filled($this->config['webhook_url'] ?? null);
    }

    public function send(Message $message): void
    {
        $content = "**{$message->emoji()} {$message->headline}**\n".implode("\n", $message->lines);

        try {
            $response = Http::timeout(10)->post($this->config['webhook_url'], ['content' => $content]);
        } catch (ConnectionException $e) {
            // redacted() keeps the webhook URL out of logs and failed_jobs
            throw NotificationFailed::redacted('Could not reach Discord: '.$e->getMessage(), $this->config['webhook_url']);
        }

        if ($response->failed()) {
            throw new NotificationFailed('Discord refused the message: HTTP '.$response->status());
        }
    }
}
```

`Message` has `level` (`success`, `error`, `warning`, `info`), `headline`, `lines` (a list of plain
strings) and `emoji()`. Throw `NotificationFailed` to have the job retry. Your channel gets the
master switch, per-event switches, queueing, retries and `linkedin:test-notification` for free.

### Without the built-in channels

Prefer Laravel notifications, Mattermost, or a database log? Leave `LINKEDIN_NOTIFY` off and listen to
the [events](#9-events) yourself:

```php
Event::listen(Shared::class, fn (Shared $e) =>
    Notification::route('slack', config('services.slack.notifications.channel'))
        ->notify(new PostedToLinkedIn($e->post)));
```

## 12. Headless: no UI

Set `LINKEDIN_UI=false` (or install with `php artisan linkedin:install --headless`) to use the
package without any of its UI:

- no views, Blade components or Livewire components are registered, and there is no view publish tag;
- the share and disconnect endpoints always answer JSON, also to plain form posts;
- the OAuth routes, the `LinkedIn` facade, auto-posting, events, commands and notifications work as before.

Connect by sending an admin to `route('linkedin-autopost.redirect')`; the callback returns to
`routes.after_connect` with `?linkedin=connected|cancelled|error|…`. To drop the routes as well, set
`routes.enabled` to `false` and drive the OAuth flow yourself through `LinkedIn::authorizationUrl()`
and `LinkedIn::completeConnection()`.

Three common headless setups:

### a) Backend only: auto-post, nothing else

No admin screens at all; someone connects once, then models post themselves.

```dotenv
LINKEDIN_UI=false
LINKEDIN_AUTOPOST=true
```

Connect from the browser by visiting `/linkedin/redirect` while logged in as someone the gate
allows, and check it from the terminal:

```bash
php artisan linkedin:status
```

### b) SPA or mobile app with Sanctum

Keep the package routes, but behind token auth and under your API prefix:

```php
'routes' => [
    'enabled' => true,
    'prefix' => 'api/linkedin',
    'middleware' => ['api', 'auth:sanctum', 'can:manage-linkedin-autopost'],
    'after_connect' => 'https://admin.example.com/settings/linkedin',   // your SPA page
],
```

```js
const api = axios.create({ baseURL: '/api', headers: { Authorization: `Bearer ${token}` } });

await api.get('/linkedin/connection');           // status
await api.post('/linkedin/share/article/42');    // share
await api.delete('/linkedin/connection');        // disconnect
```

The OAuth `redirect` and `callback` routes need a session to keep the `state` value between them, so
an `api`-only middleware stack cannot start the connection. Either register the redirect URL of an
extra web route for connecting (option c), or keep a small web page for the one-off Connect click.

### c) Your own routes and controllers

Turn the package routes off and write the few you need. The redirect URI must be set explicitly,
because the package callback route no longer exists:

```dotenv
LINKEDIN_UI=false
LINKEDIN_REDIRECT_URI=https://example.com/admin/integrations/linkedin/callback
```

```php
'routes' => ['enabled' => false],
```

```php
// routes/web.php
Route::middleware(['auth', 'can:manage-linkedin-autopost'])
    ->prefix('admin/integrations/linkedin')
    ->controller(LinkedInIntegrationController::class)
    ->group(function () {
        Route::get('connect', 'connect')->name('admin.linkedin.connect');
        Route::get('callback', 'callback');
        Route::get('/', 'show');
        Route::delete('/', 'destroy');
        Route::post('articles/{article}/share', 'share');
    });
```

```php
namespace App\Http\Controllers;

use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Siberfx\LinkedInAutopost\Exceptions\LinkedInRequestFailed;
use Siberfx\LinkedInAutopost\Exceptions\NotConnected;
use Siberfx\LinkedInAutopost\Exceptions\NotShareable;
use Siberfx\LinkedInAutopost\Facades\LinkedIn;

final class LinkedInIntegrationController
{
    public function connect(Request $request)
    {
        $state = Str::random(40);
        $request->session()->put('linkedin.state', $state);

        return redirect()->away(LinkedIn::authorizationUrl($state));
    }

    public function callback(Request $request)
    {
        abort_unless(hash_equals((string) $request->session()->pull('linkedin.state'), (string) $request->query('state')), 403);

        if ($request->filled('error')) {
            return redirect('/admin/integrations')->with('error', $request->query('error_description', 'Cancelled.'));
        }

        try {
            $connection = LinkedIn::completeConnection((string) $request->query('code'));
        } catch (LinkedInRequestFailed $e) {
            return redirect('/admin/integrations')->with('error', $e->getMessage());
        }

        return redirect('/admin/integrations')->with('success', "Connected as {$connection->name}.");
    }

    public function show()
    {
        return response()->json(LinkedIn::connection()->toArray());
    }

    public function destroy()
    {
        return response()->json(['revoked' => LinkedIn::disconnect()]);
    }

    public function share(Article $article)
    {
        try {
            $post = LinkedIn::share($article);
        } catch (NotConnected|NotShareable|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (LinkedInRequestFailed $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['post_urn' => $post->post_urn], 201);
    }
}
```

`completeConnection()` stores the token, fires `Connected` and returns the `Connection`;
`disconnect()` revokes the token at LinkedIn, forgets it and returns whether LinkedIn confirmed.

## 13. Keeping the token somewhere else

The token is stored encrypted (your `APP_KEY`) in `linkedin_connections`. Rotating `APP_KEY` makes it
unreadable; the package then reports *not connected* and you connect again. To store it elsewhere,
implement `Siberfx\LinkedInAutopost\Contracts\TokenStore` and set `token_store` in the config.
The package reads the store once per request (or queued job) and remembers the result; connect and
disconnect through the package so it stays in step.

For example, in an existing key-value `settings` table:

```php
namespace App\LinkedIn;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Siberfx\LinkedInAutopost\Contracts\TokenStore;
use Siberfx\LinkedInAutopost\Data\StoredConnection;

final class SettingsTokenStore implements TokenStore
{
    private const KEY = 'linkedin.connection';

    public function get(): ?StoredConnection
    {
        $value = Setting::query()->where('key', self::KEY)->value('value');

        if ($value === null) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($value), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;   // unreadable (e.g. APP_KEY rotated): treated as not connected
        }

        return new StoredConnection(
            accessToken: $data['access_token'],
            authorUrn: $data['author_urn'],
            name: $data['name'],
            email: $data['email'],
            picture: $data['picture'],
            scopes: $data['scopes'],
            expiresAt: $data['expires_at'] ? CarbonImmutable::parse($data['expires_at']) : null,
            connectedAt: $data['connected_at'] ? CarbonImmutable::parse($data['connected_at']) : null,
        );
    }

    public function put(StoredConnection $connection): void
    {
        Setting::query()->updateOrCreate(['key' => self::KEY], ['value' => Crypt::encryptString(json_encode([
            'access_token' => $connection->accessToken,
            'author_urn' => $connection->authorUrn,
            'name' => $connection->name,
            'email' => $connection->email,
            'picture' => $connection->picture,
            'scopes' => $connection->scopes,
            'expires_at' => $connection->expiresAt?->toIso8601String(),
            'connected_at' => $connection->connectedAt?->toIso8601String(),
        ]))]);
    }

    public function forget(): void
    {
        Setting::query()->where('key', self::KEY)->delete();
    }
}
```

```php
// config/linkedin-autopost.php
'token_store' => App\LinkedIn\SettingsTokenStore::class,
```

Always encrypt the token: it can post as the connected member.

The connection status is cached as plain data (never objects), so it works with Laravel 13's default
`cache.serializable_classes = false`.

## 14. LinkedIn API versions

Posts are sent with `LinkedIn-Version: 202609`. LinkedIn retires each version about a year after
release; when they announce a sunset, set `LINKEDIN_API_VERSION` to a newer `YYYYMM`.

```dotenv
LINKEDIN_API_VERSION=202610
```

## 15. Testing your app

Your own tests never need to reach LinkedIn, Telegram or Slack. Put a connection in the token store
and fake the HTTP calls:

```php
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Siberfx\LinkedInAutopost\Contracts\TokenStore;
use Siberfx\LinkedInAutopost\Data\StoredConnection;
use Siberfx\LinkedInAutopost\Facades\LinkedIn;
use Siberfx\LinkedInAutopost\Jobs\ShareOnLinkedIn;

beforeEach(function () {
    app(TokenStore::class)->put(new StoredConnection(
        accessToken: 'test-token',
        authorUrn: 'urn:li:person:test',
        expiresAt: CarbonImmutable::now()->addDays(60),
    ));
});

it('queues an article for LinkedIn when it is published', function () {
    Queue::fake();
    config(['linkedin-autopost.autopost.enabled' => true, 'linkedin-autopost.autopost.in_console' => true]);

    $article = Article::factory()->draft()->create();
    $article->update(['status' => 'published']);

    Queue::assertPushed(ShareOnLinkedIn::class, fn ($job) => $job->shareableId === $article->id);
});

it('shares an article', function () {
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:1']),
    ]);

    $post = LinkedIn::share(Article::factory()->published()->create());

    expect($post->post_urn)->toBe('urn:li:share:1');
    Http::assertSent(fn ($request) => $request['author'] === 'urn:li:person:test'
        && $request['content']['article']['source'] === route('blog.show', $post->shareable));
});

it('notifies Slack about the post', function () {
    config([
        'linkedin-autopost.notifications.enabled' => true,
        'linkedin-autopost.notifications.channels.slack.enabled' => true,
        'linkedin-autopost.notifications.channels.slack.webhook_url' => 'https://hooks.slack.com/services/test',
    ]);
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:1']),
        'https://hooks.slack.com/*' => Http::response('ok'),
    ]);

    LinkedIn::share(Article::factory()->published()->create());

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://hooks.slack.com/'));
});
```

Tests run in the console, so set `autopost.in_console` to `true` in tests that check auto-posting.
Define the `manage-linkedin-autopost` gate (or act as a user it allows) in tests that call the routes.

## 16. Configuration reference

| Key | Env | Default | |
|---|---|---|---|
| `client_id`, `client_secret` | `LINKEDIN_CLIENT_ID`, `LINKEDIN_CLIENT_SECRET` | — | Your LinkedIn app's credentials. |
| `redirect_uri` | `LINKEDIN_REDIRECT_URI` | the callback route | Must match a registered redirect URL exactly. |
| `scopes` | | `openid profile email w_member_social` | |
| `api_version` | `LINKEDIN_API_VERSION` | `202609` | `LinkedIn-Version` header. |
| `visibility` | `LINKEDIN_VISIBILITY` | `PUBLIC` | or `CONNECTIONS`. |
| `routes.enabled` | | `true` | `false` registers no routes. |
| `routes.prefix` / `routes.name` | | `linkedin` / `linkedin-autopost.` | |
| `routes.middleware` | | `web, auth, can:manage-linkedin-autopost` | |
| `routes.after_connect` | | `/` | Where the OAuth callback returns. |
| `autopost.enabled` | `LINKEDIN_AUTOPOST` | `false` | |
| `autopost.in_console` | | `false` | Auto-post from seeders and commands. |
| `autopost.queue_connection` / `autopost.queue` | | `null` | |
| `ui.enabled` | `LINKEDIN_UI` | `true` | `false` = headless. |
| `ui.theme` | `LINKEDIN_UI_THEME` | `tailwind` | `bootstrap` or a published folder. |
| `notifications.enabled` | `LINKEDIN_NOTIFY` | `false` | Master switch. |
| `notifications.events.*` | | all `true` | `shared`, `failed`, `token_expiring`. |
| `notifications.queue_connection` / `.queue` | | `null` | |
| `notifications.queued` | `LINKEDIN_NOTIFY_QUEUED` | `true` | `false` sends at once, without retries. |
| `notifications.tries` / `.backoff` | | `3` / `[30, 120]` | Queued mode only. |
| `notifications.channels.telegram.*` | `LINKEDIN_NOTIFY_TELEGRAM`, `LINKEDIN_TELEGRAM_BOT_TOKEN`, `LINKEDIN_TELEGRAM_CHAT_ID`, `LINKEDIN_TELEGRAM_THREAD_ID` | off | |
| `notifications.channels.slack.*` | `LINKEDIN_NOTIFY_SLACK`, `LINKEDIN_SLACK_WEBHOOK_URL` | off | |
| `notifications.channels.mail.*` | `LINKEDIN_NOTIFY_MAIL`, `LINKEDIN_NOTIFY_MAIL_TO`, `LINKEDIN_NOTIFY_MAILER` | off | Uses `config/mail.php`. |
| `token_store` | | `DatabaseTokenStore` | Any `TokenStore` class. |
| `http.timeout` | | `20` | Seconds, for LinkedIn, Telegram and Slack calls. |
| `job.tries` / `job.backoff` | | `3` / `[60, 300]` | The auto-post job. |
| `status_cache_ttl` | | `300` | Seconds the connection status is cached. |
| `expiry_warning_days` | | `7` | Default for `linkedin:check-token`. |

## 17. Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `403` on every LinkedIn route | The `manage-linkedin-autopost` gate is not defined, or refuses this user. Define it (section 2). |
| *redirect_uri does not match* on LinkedIn | Register the exact URL from `php artisan route:list --name=linkedin-autopost.callback`, or set `LINKEDIN_REDIRECT_URI`. |
| `?linkedin=error&reason=Scope "profile" is not authorized` | Add **Sign In with LinkedIn using OpenID Connect** to the app's products. |
| `?linkedin=invalid_state` | The session was lost between redirect and callback: same domain for both, `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE` on http. |
| Publishing does not post | `LINKEDIN_AUTOPOST=true`? A queue worker running? Saved through Eloquent (not a mass `update()`)? Was it already live before this save? Saved from a command or seeder (`autopost.in_console`)? |
| Status `expired` / share answers *401* | The 60-day token ran out or was revoked: connect again. Schedule `linkedin:check-token`. |
| Status `unknown` | LinkedIn could not be reached for the status check; sharing may still work. |
| Share button disabled | Its tooltip/reason says why: not connected, not live, or the gate refuses you. |
| Signed share URL answers `403` behind a proxy | Configure `TrustProxies` so the scheme and host match. |
| Tailwind components unstyled | Add the `@source` line (section 6). |
| No Telegram/Slack/email message | `php artisan linkedin:test-notification` shows which switch or setting is missing; check `failed_jobs` for `SendNotification`, and that a queue worker runs (or set `LINKEDIN_NOTIFY_QUEUED=false`). |
| Email not arriving | Test the app's mail first (`MAIL_MAILER`, `MAIL_FROM_ADDRESS`); `LINKEDIN_NOTIFY_MAIL_TO` must hold a valid address. |
| Telegram *chat not found* | The bot is not in the chat, or the chat id is wrong (groups are negative). |
| Not connected after rotating `APP_KEY` | The stored token can no longer be decrypted: connect again. |

## Testing

```bash
composer test      # Pest
composer analyse   # PHPStan level 8
composer lint      # Pint
```

## License

MIT. See [LICENSE](LICENSE).
