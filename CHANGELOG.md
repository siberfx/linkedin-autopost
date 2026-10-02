# Changelog

All notable changes to `siberfx/linkedin-autopost` are documented here. This project follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
