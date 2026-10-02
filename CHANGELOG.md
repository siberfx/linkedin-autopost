# Changelog

All notable changes to `siberfx/linkedin-autopost` are documented here. This project follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and [Semantic Versioning](https://semver.org/).

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
- `php artisan linkedin:install` and a publishable example model stub.
