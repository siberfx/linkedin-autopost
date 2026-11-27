<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Siberfx\LinkedInAutopost\Community\Community;
use Siberfx\LinkedInAutopost\Community\CommunityClient;
use Siberfx\LinkedInAutopost\Contracts\ShareableOnLinkedIn;
use Siberfx\LinkedInAutopost\Contracts\TokenStore;
use Siberfx\LinkedInAutopost\Data\Connection;
use Siberfx\LinkedInAutopost\Data\Page;
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
use Siberfx\LinkedInAutopost\Posts\LinkPost;
use Siberfx\LinkedInAutopost\Posts\PostsClient;
use Siberfx\LinkedInAutopost\Support\Columns;

final class LinkedInManager
{
    public function __construct(
        private readonly TokenStore $store,
        private readonly OAuthClient $oauth,
        private readonly PostsClient $posts,
        private readonly CommunityClient $community,
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
     * Post the model now to every selected page; see shareToPages(). Re-sharing
     * an already posted model is allowed.
     *
     * @return LinkedInPost The first page's post (the member's profile unless other pages are selected).
     *
     * @throws NotConnected|NotShareable|LinkedInRequestFailed
     * @throws InvalidArgumentException When the model's toLinkedInPost() builds an invalid post.
     */
    public function share(Model&ShareableOnLinkedIn $model, string $trigger = LinkedInPost::TRIGGER_MANUAL): LinkedInPost
    {
        $posts = $this->shareToPages($model, null, $trigger);

        /** @var LinkedInPost */
        return $posts->firstWhere('status', LinkedInPost::STATUS_POSTED) ?? $posts->first();
    }

    /**
     * Post the model now to several pages: the given page ids (author URNs), or
     * the selected pages when null.
     *
     * One page: a failure is thrown, as share() always did. Several pages: each
     * failure is recorded as a failed row and the others still post; only when
     * every page failed is the first failure thrown.
     *
     * @param  list<string>|null  $pages
     * @return Collection<int, LinkedInPost> One record per page tried: posted or failed.
     *
     * @throws NotConnected|NotShareable|LinkedInRequestFailed
     * @throws InvalidArgumentException When a page is unknown, or the model's toLinkedInPost() builds an invalid post.
     */
    public function shareToPages(Model&ShareableOnLinkedIn $model, ?array $pages = null, string $trigger = LinkedInPost::TRIGGER_MANUAL): Collection
    {
        $stored = $this->stored() ?? throw new NotConnected;

        if (! $model->isLiveForLinkedIn()) {
            throw new NotShareable;
        }

        $targets = $pages === null ? $stored->selectedPageIds() : $this->validPageIds($stored, $pages);

        // Built once: an invalid post is the model's problem, not each page's.
        $post = $model->toLinkedInPost();

        if (count($targets) === 1) {
            return collect([$this->shareToPage($model, $targets[0], $trigger, $post)]);
        }

        $records = collect();
        $failure = null;

        foreach ($targets as $page) {
            try {
                $records->push($this->shareToPage($model, $page, $trigger, $post));
            } catch (LinkedInRequestFailed $e) {
                $failure ??= $e;
                $records->push($this->recordFailure($model, $page, $trigger, $e));
            }
        }

        if ($failure !== null && $records->doesntContain('status', LinkedInPost::STATUS_POSTED)) {
            throw $failure;
        }

        return $records;
    }

    /**
     * Post the model now to one page and record it. Throws on failure without
     * recording a failed row: the caller decides, as a queued job retries first.
     *
     * @throws NotConnected|NotShareable|LinkedInRequestFailed
     * @throws InvalidArgumentException When the page is unknown, or the model's toLinkedInPost() builds an invalid post.
     */
    public function shareToPage(Model&ShareableOnLinkedIn $model, string $page, string $trigger = LinkedInPost::TRIGGER_MANUAL, ?LinkPost $post = null): LinkedInPost
    {
        $stored = $this->stored() ?? throw new NotConnected;

        if (! $model->isLiveForLinkedIn()) {
            throw new NotShareable;
        }

        [$page] = $this->validPageIds($stored, [$page]);

        try {
            $urn = $this->posts->create($stored->accessToken, $page, $post ?? $model->toLinkedInPost());
        } catch (LinkedInRequestFailed $e) {
            if ($e->isUnauthorized()) {
                $this->remember($stored, $this->connection()->withStatus('expired'));
            }

            throw $e;
        }

        $record = LinkedInPost::query()->create($this->withPage([
            'shareable_type' => $model->getMorphClass(),
            'shareable_id' => $model->getKey(),
            'post_urn' => $urn,
            'status' => LinkedInPost::STATUS_POSTED,
            'trigger' => $trigger,
            'posted_at' => CarbonImmutable::now(),
        ], $page));

        event(new Shared($model, $record));

        return $record;
    }

    /** @return list<Page> Where posts can go: the member's profile, then the LinkedIn Pages they administer. Empty when not connected. */
    public function pages(): array
    {
        return $this->stored()?->availablePages() ?? [];
    }

    /** @return list<Page> Where posts go. Empty when not connected. */
    public function selectedPages(): array
    {
        return $this->stored()?->selectedPages() ?? [];
    }

    /**
     * Choose the pages posts go to: one or several ids from pages().
     *
     * @param  list<string>  $pages
     *
     * @throws NotConnected
     * @throws InvalidArgumentException When none is given or one is unknown.
     */
    public function selectPages(array $pages): Connection
    {
        $stored = $this->stored() ?? throw new NotConnected;
        $updated = $stored->withSelectedPages($this->validPageIds($stored, $pages));

        $this->store->put($updated);
        $this->forgetStored();

        $connection = $this->connection()->withSelectedPages($updated->selectedPageIds());
        $this->remember($updated, $connection);

        return $connection;
    }

    /**
     * LinkedIn's Community Management API for this connection: posts,
     * comments, reactions, engagement and Page statistics.
     *
     * @throws NotConnected
     */
    public function community(): Community
    {
        $stored = $this->stored() ?? throw new NotConnected;

        return new Community(
            $this->community,
            $stored,
            fn () => $this->remember($stored, $this->connection()->withStatus('expired')),
        );
    }

    public function queue(Model&ShareableOnLinkedIn $model): void
    {
        $job = new ShareOnLinkedIn($model->getMorphClass(), $model->getKey());

        dispatch($job
            ->onConnection(config('linkedin-autopost.autopost.queue_connection'))
            ->onQueue(config('linkedin-autopost.autopost.queue')));
    }

    /** Whether the model was posted: to one page (author URN), or to any. */
    public function wasPosted(Model $model, ?string $page = null): bool
    {
        return LinkedInPost::query()
            ->for($model)
            ->posted()
            ->when($page !== null, fn ($query) => $query->forPage((string) $page, $this->stored()?->authorUrn))
            ->exists();
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
            pages: $stored->availablePages(),
            selectedPages: $stored->selectedPageIds(),
        );
    }

    /**
     * @param  array<mixed>  $pages
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private function validPageIds(StoredConnection $stored, array $pages): array
    {
        $available = array_map(fn (Page $page) => $page->id, $stored->availablePages());
        $ids = array_values(array_unique(array_map(fn ($page) => is_scalar($page) ? (string) $page : '', $pages)));

        if ($ids === []) {
            throw new InvalidArgumentException('Choose at least one LinkedIn page.');
        }

        foreach ($ids as $id) {
            if (! in_array($id, $available, true)) {
                throw new InvalidArgumentException("[{$id}] is not a LinkedIn page this account can post to.");
            }
        }

        return $ids;
    }

    /**
     * The page goes into the record once the 1.2 migration has added its column.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withPage(array $attributes, string $page): array
    {
        return Columns::has('linkedin_posts', 'author_urn') ? $attributes + ['author_urn' => $page] : $attributes;
    }

    private function recordFailure(Model $model, string $page, string $trigger, LinkedInRequestFailed $e): LinkedInPost
    {
        return LinkedInPost::query()->create($this->withPage([
            'shareable_type' => $model->getMorphClass(),
            'shareable_id' => $model->getKey(),
            'status' => LinkedInPost::STATUS_FAILED,
            'trigger' => $trigger,
            'error' => mb_substr($e->getMessage(), 0, 2000),
            'posted_at' => null,
        ], $page));
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
