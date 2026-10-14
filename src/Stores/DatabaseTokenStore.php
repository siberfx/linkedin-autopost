<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Stores;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Siberfx\LinkedInAutopost\Contracts\TokenStore;
use Siberfx\LinkedInAutopost\Data\Page;
use Siberfx\LinkedInAutopost\Data\StoredConnection;
use Siberfx\LinkedInAutopost\Models\LinkedInConnection;
use Siberfx\LinkedInAutopost\Support\Columns;

final class DatabaseTokenStore implements TokenStore
{
    public function get(): ?StoredConnection
    {
        $row = LinkedInConnection::query()->latest('id')->first();

        if ($row === null) {
            return null;
        }

        try {
            $token = $row->access_token;
        } catch (DecryptException) { // @phpstan-ignore catch.neverThrown (thrown by the encrypted cast on property access)
            // APP_KEY changed since connecting: the token is unreadable, so
            // the app is effectively disconnected and must connect again.
            return null;
        }

        if ($token === '') {
            return null;
        }

        return new StoredConnection(
            accessToken: $token,
            authorUrn: $row->author_urn,
            name: $row->name,
            email: $row->email,
            picture: $row->picture,
            scopes: array_values($row->scopes ?? []), // @phpstan-ignore arrayValues.list (stored JSON is not guaranteed to be a list)
            expiresAt: $row->expires_at,
            connectedAt: $row->connected_at,
            pages: Page::listFrom($row->pages ?? []),
            selectedPages: array_values(array_filter($row->selected_pages ?? [], 'is_string')),
        );
    }

    public function put(StoredConnection $connection): void
    {
        $attributes = [
            'access_token' => $connection->accessToken,
            'author_urn' => $connection->authorUrn,
            'name' => $connection->name,
            'email' => $connection->email,
            'picture' => $connection->picture,
            'scopes' => $connection->scopes,
            'expires_at' => $connection->expiresAt,
            'connected_at' => $connection->connectedAt,
        ];

        // Until the 1.2 migration runs there is only the member's profile to post to.
        if (Columns::has('linkedin_connections', 'pages')) {
            $attributes['pages'] = array_map(fn (Page $page) => $page->toArray(), $connection->pages);
            $attributes['selected_pages'] = $connection->selectedPages;
        }

        DB::transaction(function () use ($attributes): void {
            LinkedInConnection::query()->delete();
            LinkedInConnection::query()->create($attributes);
        });
    }

    public function forget(): void
    {
        LinkedInConnection::query()->delete();
    }
}
