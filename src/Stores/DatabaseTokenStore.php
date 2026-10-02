<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Stores;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Siberfx\LinkedInAutopost\Contracts\TokenStore;
use Siberfx\LinkedInAutopost\Data\StoredConnection;
use Siberfx\LinkedInAutopost\Models\LinkedInConnection;

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
        } catch (DecryptException) {
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
            scopes: array_values($row->scopes ?? []),
            expiresAt: $row->expires_at,
            connectedAt: $row->connected_at,
        );
    }

    public function put(StoredConnection $connection): void
    {
        DB::transaction(function () use ($connection): void {
            LinkedInConnection::query()->delete();

            LinkedInConnection::query()->create([
                'access_token' => $connection->accessToken,
                'author_urn' => $connection->authorUrn,
                'name' => $connection->name,
                'email' => $connection->email,
                'picture' => $connection->picture,
                'scopes' => $connection->scopes,
                'expires_at' => $connection->expiresAt,
                'connected_at' => $connection->connectedAt,
            ]);
        });
    }

    public function forget(): void
    {
        LinkedInConnection::query()->delete();
    }
}
