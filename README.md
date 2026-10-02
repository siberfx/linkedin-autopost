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
- Optional Telegram and Slack messages on every post, failed share and expiring token.

Requires PHP 8.4+ and Laravel 12 or 13.

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

> **Using UUID or ULID keys?** Edit the published `create_linkedin_posts_table` migration: replace
> `morphs('shareable')` with `uuidMorphs('shareable')` / `ulidMorphs('shareable')` before migrating.

`.env`:

```dotenv
LINKEDIN_CLIENT_ID=...
LINKEDIN_CLIENT_SECRET=...
# LINKEDIN_AUTOPOST=true   # switch on when ready
LINKEDIN_UI_THEME=tailwind     # or bootstrap
# LINKEDIN_REDIRECT_URI=https://example.com/linkedin/callback   # only if it differs from the route
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

Your definition wins whatever the provider order. The routes sit behind
`['web', 'auth', 'can:manage-linkedin-autopost']`; the Livewire actions check the same gate, and the
components show the connection read-only and the share button disabled for people it refuses.
Change the middleware or the page you come back to in `config/linkedin-autopost.php`:

```php
'routes' => [
    'middleware' => ['web', 'auth', 'can:manage-linkedin-autopost'],
    'after_connect' => '/admin/settings',
],
```

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

With `LINKEDIN_AUTOPOST=true`, an `Article` is queued for LinkedIn when it is **created published**
or **changes from draft to published** — once. Editing a published article does not post again.
Seeders and Artisan commands do not auto-post (set `autopost.in_console` to allow it). Auto-post
reacts to model saves through Eloquent events, so mass `update()` queries and `saveQuietly()` do not
auto-post.

Run a queue worker; the job is dispatched after the database transaction commits, is unique per
model while it waits, and retries 3 times when LinkedIn is busy or unreachable (429, 5xx, network).
A request LinkedIn refuses outright (401, 403, 422 …) or an invalid post fails at once, without
retries. With the `sync` queue the share runs inside the request that saved the model, so a
LinkedIn failure surfaces there — use a real queue.

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
$article->wasPostedToLinkedIn();     // bool
$article->linkedInPosts;             // history: post_urn, status, trigger, error, posted_at
```

`share()` throws `NotConnected`, `NotShareable` (not live), `LinkedInRequestFailed` (LinkedIn's
message) or `InvalidArgumentException` (your `toLinkedInPost()` built an invalid URL). A post LinkedIn
accepted without returning its id is recorded as posted with a `null` `post_urn`.

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
React when a post goes out, e.g. to log the link:

```php
Event::listen(Shared::class, function (Shared $event) {
    logger()->info('Posted to LinkedIn', ['urn' => $event->post->post_urn]);
});
```

## 8. Build your admin UI on the endpoints

| Method | URL | Returns |
|---|---|---|
| GET | `/linkedin/connection` | `{"data": {connected, status, name, email, picture, author_urn, scopes, connected_at, expires_at, days_left}}` |
| DELETE | `/linkedin/connection` | Revokes and forgets the token; `{"message", "revoked", "data"}` |
| POST | `/linkedin/share/{type}/{id}` | `201 {"data": {post_urn, posted_at}}` (`post_urn` is `null` when LinkedIn accepted the post without returning its id), `422` not connected / not live / invalid post URL, `502` LinkedIn refused, `404` unknown |

Every endpoint answers `403` to people the `manage-linkedin-autopost` gate refuses.

`status` is `active`, `expired`, `revoked` or `unknown` (LinkedIn unreachable). The share endpoint
accepts **morph-map aliases only**:

```php
Relation::enforceMorphMap(['article' => Article::class]);
```

Without a morph map, call `LinkedIn::share()` from your own controller.

## 9. Events

| Event | When |
|---|---|
| `Connected` | After a successful connect (`$event->connection`). |
| `Disconnected` | After disconnect (`$event->revoked`). |
| `Shared` | After every successful post (`$event->shareable`, `$event->post`). |
| `ShareFailed` | An automatic share gave up after its last attempt (`$event->exception`). |
| `TokenExpiringSoon` | From `linkedin:check-token` (`$event->daysLeft`). |

```php
Event::listen(ShareFailed::class, fn (ShareFailed $e) =>
    Notification::route('mail', 'admin@example.com')->notify(new LinkedInShareFailed($e)));
```

## 10. Commands and the 60-day token

```bash
php artisan linkedin:status
php artisan linkedin:share article 42 [--force]
php artisan linkedin:disconnect [--force]
php artisan linkedin:check-token [--days=7]
php artisan linkedin:test-notification [--channel=slack]
```

LinkedIn member tokens last **60 days**, and self-serve apps get **no refresh token** — someone has to
click Connect again before it runs out. Schedule the check and listen for `TokenExpiringSoon`:

```php
Schedule::command('linkedin:check-token')->daily();
```

## 11. Telegram and Slack notifications

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
```

Choose which events send a message:

```php
'notifications' => [
    'events' => ['shared' => true, 'failed' => true, 'token_expiring' => true],
    'queue_connection' => null,
    'queue' => null,
    // …
],
```

Then check the settings:

```bash
php artisan linkedin:test-notification [--channel=telegram]
```

Each channel is sent from its own queued job (`SendNotification`), retried `tries` times with
`backoff`. A failing channel never fails the share or blocks the other channels; on the `sync` queue
the error is reported through your exception handler. The token-expiry message needs the scheduled
`linkedin:check-token` from the previous section.

To add a channel of your own (Teams, Discord…), implement
`Siberfx\LinkedInAutopost\Contracts\NotificationChannel` and list it; the container builds it with
its config array as `$config`:

```php
'channels' => [
    // telegram, slack…
    'teams' => ['enabled' => true, 'class' => App\Notifications\TeamsChannel::class, 'webhook_url' => env('TEAMS_WEBHOOK_URL')],
],
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

## 13. Keeping the token somewhere else

The token is stored encrypted (your `APP_KEY`) in `linkedin_connections`. Rotating `APP_KEY` makes it
unreadable; the package then reports *not connected* and you connect again. To store it elsewhere,
implement `Siberfx\LinkedInAutopost\Contracts\TokenStore` and set `token_store` in the config.
The package reads the store once per request (or queued job) and remembers the result; connect and
disconnect through the package so it stays in step.

The connection status is cached as plain data (never objects), so it works with Laravel 13's default
`cache.serializable_classes = false`.

## 14. LinkedIn API versions

Posts are sent with `LinkedIn-Version: 202609`. LinkedIn retires each version about a year after
release; when they announce a sunset, set `LINKEDIN_API_VERSION` to a newer `YYYYMM`.

## Testing

```bash
composer test      # Pest
composer analyse   # PHPStan level 8
composer lint      # Pint
```

## License

MIT. See [LICENSE](LICENSE).
