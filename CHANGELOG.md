# Changelog

All notable changes to `siberfx/linkedin-autopost` are documented here. This project follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.2.0] - 2026-10-15

### Added
- Pages: a connection now lists where posts can go, the member's own profile and (opt-in) the LinkedIn Pages the member administers, so you can choose one or several of them. The `pages` and `selected_pages` keys are in `GET /linkedin/connection`, `Connection::toArray()`, the return value of `completeConnection()` and the `Connected` event. Each page has `id` (the author URN), `type` (`member` or `organization`), `name`, `picture` and `selected`.
- `LINKEDIN_ORGANIZATIONS=true` (`organizations.enabled`) asks for `r_organization_admin` and `w_organization_social` (`organizations.scopes`) and reads the Pages where the member is an `ADMINISTRATOR` or `CONTENT_ADMINISTRATOR` (`organizations.roles`). This needs LinkedIn's Community Management API product. If the Pages cannot be read, connecting still succeeds with the profile only and a warning is logged.
- `GET /linkedin/connection/pages` and `PUT /linkedin/connection/pages` (`{"pages": [...]}`) to read and choose the pages. `422` for an empty list, an unknown page or no connection. HTML forms redirect back with a flash message.
- `LinkedIn::pages()`, `selectedPages()`, `selectPages()`, `shareToPages()` and `shareToPage()`. `wasPosted()` takes an optional page.
- The connection card (Tailwind and Bootstrap, Blade and Livewire) shows a "Post to" checkbox list when there is more than one page.
- The share endpoint accepts an optional `pages` list for a one-off share, and its response lists each page's result under `data.posts`. `linkedin:share` takes `--page` (repeatable), and `linkedin:status` lists the pages.
- `linkedin_posts.author_urn` records the page each post went to. New migration `update_linkedin_tables_for_pages`.

### Changed
- Every share goes to each selected page. With several pages, a failing page is recorded as a `failed` row and the others still post. `share()` returns the first post that went out and only throws when every page failed. A single page behaves exactly as before.
- The auto-post job retries only the pages that have not posted yet, so a retry never posts twice to the same page. A permanent failure on an organization Page names that Page in the error.
- `StoredConnection` has two new optional constructor arguments, `pages` and `selectedPages`. Custom token stores keep working without them, with the profile as the only page.

### Upgrading
- Publish and run the new migration: `php artisan vendor:publish --tag=linkedin-autopost-migrations && php artisan migrate`. Until it runs, the package works as before and posts to the profile only.

## [1.1.0] - 2026-10-02

### Added
- Telegram, Slack and email notifications when a post goes out, an automatic share gives up, or the token is about to expire. Off by default; a master switch (`LINKEDIN_NOTIFY`), one per channel (`LINKEDIN_NOTIFY_TELEGRAM`, `LINKEDIN_NOTIFY_SLACK`, `LINKEDIN_NOTIFY_MAIL`) and one per event under `notifications.events`. Email goes through the app's own mailer (`LINKEDIN_NOTIFY_MAIL_TO`, optional `LINKEDIN_NOTIFY_MAILER`) as the `NotificationMail` mailable. Sent from one queued `SendNotification` job per channel, or at once with `LINKEDIN_NOTIFY_QUEUED=false`; a failing channel never fails the share. Add your own channel by implementing `Contracts\NotificationChannel`.
- `illuminate/mail` is now a required component.
- `php artisan linkedin:test-notification [--channel=…]` sends a test message to the enabled channels.
- Headless mode: `LINKEDIN_UI=false` (or `linkedin:install --headless`) registers no views, Blade or Livewire components, and the share and disconnect endpoints answer JSON only.

### Fixed
- CI: the experimental PHP 8.6 job ignores upper PHP bounds of dependencies (`--ignore-platform-req=php+`), so it installs instead of failing on `nette/schema`.

## [1.0.0] - 2026-10-02

### Added
- Connect one LinkedIn member account through OAuth (`/linkedin/redirect`, `/linkedin/callback`), with LinkedIn's own error descriptions passed back to your app.
- Share Eloquent models through the Posts API (`/rest/posts`, `LinkedIn-Version` header), with link previews and little-format escaping of the post text.
- Auto-post when a model becomes live: the `ShareableOnLinkedIn` contract and the `PostsToLinkedIn` trait. Off by default, never from the console unless enabled, at most once per model, dispatched after commit.
- Share history in `linkedin_posts`; the token encrypted in `linkedin_connections` behind a swappable `TokenStore`.
- Connection status from LinkedIn's token introspection: member, scopes, issued and expiry dates, active/expired/revoked.
- JSON endpoints for status, disconnect and share; Artisan commands `linkedin:status`, `linkedin:disconnect`, `linkedin:share`, `linkedin:check-token`; events `Connected`, `Disconnected`, `Shared`, `ShareFailed`, `TokenExpiringSoon`.
- PHP 8.4 and 8.5 (8.6 accepted), Laravel 12 and 13.
- Ready-made admin UI in Tailwind and Bootstrap 5, switched with `LINKEDIN_UI_THEME`: a connection card (`<x-linkedin-autopost::connection />`), a share button (`<x-linkedin-autopost::share-button :model="…" />`) and a flash message component. Livewire variants (`linkedin-autopost.connection`, `linkedin-autopost.share-button`) when Livewire is installed. Views are publishable, and a custom theme is a folder of three views.
- The share and disconnect routes answer HTML forms (redirect back with a message) as well as JSON; a signed share route lets the button work without a morph map.
- `php artisan linkedin:install` and a publishable example model stub; it prints the redirect URI to register (honouring `LINKEDIN_REDIRECT_URI`) and the gate to define.
- The connection status is cached as plain arrays, so it works with Laravel 13's `cache.serializable_classes = false`.
- Non-ASCII URLs are accepted: paths are percent-encoded and internationalised hosts converted to punycode (with `ext-intl`). An invalid post URL answers `422` / an error message instead of a server error.
- No duplicate posts on retries: a `2xx` without a post id is recorded as posted (with a `null` `post_urn`); the auto-post job is unique per model and fails at once, without retrying, on a `4xx` other than `429` or an invalid post. `429`, `5xx` and network errors are still retried.
- The stored connection is read once per request instead of once per share button.

### Security
- Every route, Livewire action and component control is guarded by the `manage-linkedin-autopost` gate (default route middleware `['web', 'auth', 'can:manage-linkedin-autopost']`). The package defines the gate to deny everyone; define it in your app to choose who may connect, disconnect, see the connection and share.
