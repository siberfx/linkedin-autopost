<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Siberfx\LinkedInAutopost\Contracts\ShareableOnLinkedIn;
use Siberfx\LinkedInAutopost\Contracts\TokenStore;
use Siberfx\LinkedInAutopost\Data\Connection;
use Siberfx\LinkedInAutopost\Data\StoredConnection;
use Siberfx\LinkedInAutopost\Events\Connected;
use Siberfx\LinkedInAutopost\Events\Disconnected;
use Siberfx\LinkedInAutopost\Events\Shared;
use Siberfx\LinkedInAutopost\Exceptions\LinkedInRequestFailed;
use Siberfx\LinkedInAutopost\Exceptions\NotConnected;
use Siberfx\LinkedInAutopost\Exceptions\NotShareable;
use Siberfx\LinkedInAutopost\Jobs\ShareOnLinkedIn;
use Siberfx\LinkedInAutopost\Models\LinkedInPost;
use Siberfx\LinkedInAutopost\OAuth\OAuthClient;
use Siberfx\LinkedInAutopost\Posts\PostsClient;

final class LinkedInManager
{
    public function __construct(
        private readonly TokenStore $store,
        private readonly OAuthClient $oauth,
        private readonly PostsClient $posts,
    ) {}

    /** The manager is scoped per request/job, so this is read (query + decrypt) at most once each. */
    private ?StoredConnection $stored = null;

    private bool $storedLoaded = false;

    public function isConnected(): bool
    {
        return $this->stored() !== null;
    }

    public function connection(): Connection
    {
        $stored = $this->stored();

        if ($stored === null) {
            return Connection::disconnected();
        }

        $cached = Cache::get($this->cacheKey($stored));

        if (is_array($cached)) {
            return Connection::fromCache($cached);
        }

        $connection = $this->fetchStatus($stored);
        $this->remember($stored, $connection);

        return $connection;
    }

    /**
     * Post the model now. Re-sharing an already posted model is allowed.
     *
     * @throws NotConnected|NotShareable|LinkedInRequestFailed
     * @throws InvalidArgumentException When the model's toLinkedInPost() builds an invalid post.
     */
    public function share(Model&ShareableOnLinkedIn $model, string $trigger = LinkedInPost::TRIGGER_MANUAL): LinkedInPost
    {
        $stored = $this->stored() ?? throw new NotConnected;

        if (! $model->isLiveForLinkedIn()) {
            throw new NotShareable;
        }

        try {
            $urn = $this->posts->create($stored->accessToken, $stored->authorUrn, $model->toLinkedInPost());
        } catch (LinkedInRequestFailed $e) {
            if ($e->isUnauthorized()) {
                $this->remember($stored, $this->connection()->withStatus('expired'));
            }

            throw $e;
        }

        $record = LinkedInPost::query()->create([
            'shareable_type' => $model->getMorphClass(),
            'shareable_id' => $model->getKey(),
            'post_urn' => $urn,
            'status' => LinkedInPost::STATUS_POSTED,
            'trigger' => $trigger,
            'posted_at' => CarbonImmutable::now(),
        ]);

        event(new Shared($model, $record));

        return $record;
    }

    public function queue(Model&ShareableOnLinkedIn $model): void
    {
        $job = new ShareOnLinkedIn($model->getMorphClass(), $model->getKey());

        dispatch($job
            ->onConnection(config('linkedin-autopost.autopost.queue_connection'))
            ->onQueue(config('linkedin-autopost.autopost.queue')));
    }

    public function wasPosted(Model $model): bool
    {
        return LinkedInPost::query()->for($model)->posted()->exists();
    }

    /** @return bool Whether LinkedIn confirmed the revocation; the token is forgotten either way. */
    public function disconnect(): bool
    {
        $stored = $this->stored();
        $revoked = $stored === null || $this->oauth->revoke($stored->accessToken);

        if ($stored !== null) {
            Cache::forget($this->cacheKey($stored));
        }

        $this->store->forget();
        $this->forgetStored();
        event(new Disconnected($revoked));

        return $revoked;
    }

    public function authorizationUrl(string $state): string
    {
        return $this->oauth->authorizationUrl($state);
    }

    /** @throws LinkedInRequestFailed */
    public function completeConnection(string $code): Connection
    {
        $stored = $this->oauth->exchange($code);
        $this->store->put($stored);
        $this->forgetStored();

        $connection = Connection::fromStored($stored, 'active');
        $this->remember($stored, $connection);
        event(new Connected($connection));

        return $connection;
    }

    private function stored(): ?StoredConnection
    {
        if (! $this->storedLoaded) {
            $this->stored = $this->store->get();
            $this->storedLoaded = true;
        }

        return $this->stored;
    }

    private function forgetStored(): void
    {
        $this->stored = null;
        $this->storedLoaded = false;
    }

    private function fetchStatus(StoredConnection $stored): Connection
    {
        $introspection = $this->oauth->introspect($stored->accessToken);

        if ($introspection === null) {
            return Connection::fromStored($stored, 'unknown');
        }

        $active = (bool) ($introspection['active'] ?? false);
        $status = is_string($introspection['status'] ?? null) ? $introspection['status'] : ($active ? 'active' : 'expired');
        $profile = $active ? $this->oauth->userinfo($stored->accessToken) : null;

        return new Connection(
            connected: true,
            status: $status,
            name: self::string($profile['name'] ?? null) ?? $stored->name,
            email: self::string($profile['email'] ?? null) ?? $stored->email,
            picture: self::string($profile['picture'] ?? null) ?? $stored->picture,
            authorUrn: $stored->authorUrn,
            scopes: isset($introspection['scope']) ? OAuthClient::scopes($introspection['scope']) : $stored->scopes,
            connectedAt: isset($introspection['created_at']) ? CarbonImmutable::createFromTimestamp((int) $introspection['created_at']) : $stored->connectedAt,
            expiresAt: isset($introspection['expires_at']) ? CarbonImmutable::createFromTimestamp((int) $introspection['expires_at']) : $stored->expiresAt,
        );
    }

    /** Only plain arrays go into the cache; see Connection::toCache(). */
    private function remember(StoredConnection $stored, Connection $connection): void
    {
        Cache::put($this->cacheKey($stored), $connection->toCache(), $this->cacheTtl());
    }

    private function cacheKey(StoredConnection $stored): string
    {
        return 'linkedin-autopost:status:'.hash('sha256', $stored->accessToken);
    }

    private function cacheTtl(): int
    {
        return (int) config('linkedin-autopost.status_cache_ttl', 300);
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
