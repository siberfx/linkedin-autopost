<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Community;

use Carbon\CarbonInterface;
use Closure;
use InvalidArgumentException;
use Siberfx\LinkedInAutopost\Data\Page;
use Siberfx\LinkedInAutopost\Data\StoredConnection;
use Siberfx\LinkedInAutopost\Exceptions\LinkedInRequestFailed;
use Siberfx\LinkedInAutopost\Models\LinkedInPost;

/**
 * LinkedIn's Community Management API for the connected account: read and
 * delete posts, moderate comments, react, and read engagement and Page
 * statistics. Get it from LinkedIn::community().
 *
 * A post is a post URN (urn:li:share:…, urn:li:ugcPost:…) or a LinkedInPost
 * record from the share history. The actor (who comments or reacts) must be
 * one of the connection's pages; it defaults to the page the record was posted
 * to, else the first selected page.
 */
final class Community
{
    /** @param  Closure(): void  $onUnauthorized  Marks the connection expired. */
    public function __construct(
        private readonly CommunityClient $client,
        private readonly StoredConnection $connection,
        private readonly Closure $onUnauthorized,
    ) {}

    /**
     * The LinkedIn Page used when a call names none: community.page, else the
     * first selected LinkedIn Page, else the first one. Null when the
     * connection has no LinkedIn Page.
     *
     * @throws InvalidArgumentException When community.page is not one of the connection's pages.
     */
    public function defaultPage(): ?Page
    {
        $configured = self::configuredPage();

        if ($configured !== null) {
            return $this->page($configured);
        }

        foreach ($this->connection->selectedPageIds() as $id) {
            $page = $this->findPage($id);

            if ($page?->type === Page::TYPE_ORGANIZATION) {
                return $page;
            }
        }

        foreach ($this->connection->availablePages() as $page) {
            if ($page->type === Page::TYPE_ORGANIZATION) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Recent posts of a page, as LinkedIn returns them (id, author, commentary,
     * content, lifecycleState, publishedAt, …). Without a page: defaultPage(),
     * else the member's profile.
     *
     * @return list<array<string, mixed>>
     *
     * @throws LinkedInRequestFailed
     * @throws InvalidArgumentException When the page is not one of the connection's pages.
     */
    public function posts(?string $page = null, int $count = 10, int $start = 0): array
    {
        $author = $page === null ? ($this->defaultPage()->id ?? $this->defaultActor()) : $this->page($page)->id;

        return $this->call(fn () => $this->client->posts($this->connection->accessToken, $author, self::count($count), max(0, $start)));
    }

    /**
     * @return array<string, mixed>|null Null when LinkedIn no longer has the post.
     *
     * @throws LinkedInRequestFailed
     */
    public function post(string|LinkedInPost $post): ?array
    {
        return $this->call(fn () => $this->client->post($this->connection->accessToken, $this->urn($post)));
    }

    /**
     * Delete the post from LinkedIn. A LinkedInPost record is marked "deleted",
     * so it no longer counts as posted. A post that is already gone is fine.
     *
     * @throws LinkedInRequestFailed
     */
    public function deletePost(string|LinkedInPost $post): void
    {
        $urn = $this->urn($post);

        $this->call(fn () => $this->client->deletePost($this->connection->accessToken, $urn));

        LinkedInPost::query()->where('post_urn', $urn)->update(['status' => LinkedInPost::STATUS_DELETED]);
    }

    /**
     * @return list<Comment>
     *
     * @throws LinkedInRequestFailed
     */
    public function comments(string|LinkedInPost $post, int $count = 10, int $start = 0): array
    {
        $comments = $this->call(fn () => $this->client->comments($this->connection->accessToken, $this->urn($post), self::count($count), max(0, $start)));

        return array_map(Comment::fromApi(...), $comments);
    }

    /**
     * Comment on a post as the actor, or reply to a comment with $replyTo.
     *
     * @throws LinkedInRequestFailed
     * @throws InvalidArgumentException When the text is empty or the actor is not one of the connection's pages.
     */
    public function comment(string|LinkedInPost $post, string $text, ?string $actor = null, Comment|string|null $replyTo = null): Comment
    {
        if (trim($text) === '') {
            throw new InvalidArgumentException('A LinkedIn comment needs some text.');
        }

        $urn = $this->urn($post);
        $actor = $this->actor($post, $actor);
        $parent = $replyTo instanceof Comment ? ($replyTo->urn ?? throw new InvalidArgumentException('This comment has no URN to reply to.')) : $replyTo;

        $comment = $this->call(fn () => $this->client->comment($this->connection->accessToken, $actor, $urn, $text, $parent));

        return Comment::fromApi($comment);
    }

    /**
     * Delete a comment, made by the actor or, as a Page admin, by anyone on the Page's post.
     *
     * @param  Comment|string  $comment  A Comment, or its id.
     *
     * @throws LinkedInRequestFailed
     */
    public function deleteComment(string|LinkedInPost $post, Comment|string $comment, ?string $actor = null): void
    {
        $id = $comment instanceof Comment ? $comment->id : $comment;

        if ($id === '') {
            throw new InvalidArgumentException('The comment id is missing.');
        }

        $this->call(fn () => $this->client->deleteComment($this->connection->accessToken, $this->actor($post, $actor), $this->urn($post), $id));
    }

    /**
     * @return list<Reaction>
     *
     * @throws LinkedInRequestFailed
     */
    public function reactions(string|LinkedInPost $post, int $count = 10, int $start = 0): array
    {
        $reactions = $this->call(fn () => $this->client->reactions($this->connection->accessToken, $this->urn($post), self::count($count), max(0, $start)));

        return array_map(Reaction::fromApi(...), $reactions);
    }

    /**
     * React to a post or comment (pass the comment's URN as the post).
     *
     * @throws LinkedInRequestFailed
     * @throws InvalidArgumentException When the type is not one of Reaction::TYPES.
     */
    public function react(string|LinkedInPost $post, string $type = Reaction::LIKE, ?string $actor = null): void
    {
        $type = strtoupper($type);

        if (! in_array($type, Reaction::TYPES, true)) {
            throw new InvalidArgumentException("[{$type}] is not a LinkedIn reaction; use one of ".implode(', ', Reaction::TYPES).'.');
        }

        $this->call(fn () => $this->client->react($this->connection->accessToken, $this->actor($post, $actor), $this->urn($post), $type));
    }

    /** @throws LinkedInRequestFailed */
    public function unreact(string|LinkedInPost $post, ?string $actor = null): void
    {
        $this->call(fn () => $this->client->unreact($this->connection->accessToken, $this->actor($post, $actor), $this->urn($post)));
    }

    /**
     * Reaction and comment counts of a post.
     *
     * @throws LinkedInRequestFailed
     */
    public function summary(string|LinkedInPost $post): SocialSummary
    {
        return SocialSummary::fromApi($this->call(fn () => $this->client->socialMetadata($this->connection->accessToken, $this->urn($post))));
    }

    /**
     * Followers of a LinkedIn Page; defaultPage() when none is given.
     *
     * @throws LinkedInRequestFailed
     * @throws InvalidArgumentException When it is not one of the connection's LinkedIn Pages.
     */
    public function followers(?string $organization = null): int
    {
        $urn = $this->organization($organization);

        return $this->call(fn () => $this->client->followerCount($this->connection->accessToken, $urn));
    }

    /**
     * Followers and share statistics of a LinkedIn Page: lifetime totals, or
     * the sum of each day from $from to $to (default now). defaultPage() when
     * no Page is given. Needs rw_organization_admin.
     *
     * @throws LinkedInRequestFailed
     * @throws InvalidArgumentException When it is not one of the connection's LinkedIn Pages.
     */
    public function statistics(?string $organization = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null): PageStatistics
    {
        $urn = $this->organization($organization);
        $token = $this->connection->accessToken;

        return $this->call(fn () => PageStatistics::fromApi(
            $urn,
            $this->client->followerCount($token, $urn),
            $this->client->shareStatistics($token, $urn, $from, $to),
        ));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     *
     * @throws LinkedInRequestFailed
     */
    private function call(Closure $call): mixed
    {
        try {
            return $call();
        } catch (LinkedInRequestFailed $e) {
            if ($e->isUnauthorized()) {
                ($this->onUnauthorized)();
            }

            throw $e;
        }
    }

    private function urn(string|LinkedInPost $post): string
    {
        $urn = $post instanceof LinkedInPost ? $post->post_urn : $post;

        if (! is_string($urn) || ! str_starts_with($urn, 'urn:li:')) {
            throw new InvalidArgumentException($post instanceof LinkedInPost
                ? 'This share has no LinkedIn post id, so it cannot be looked up on LinkedIn.'
                : "[{$urn}] is not a LinkedIn URN.");
        }

        return $urn;
    }

    private function actor(string|LinkedInPost $post, ?string $actor): string
    {
        if ($actor !== null) {
            return $this->page($actor)->id;
        }

        $postedAs = $post instanceof LinkedInPost ? ($post->getAttributes()['author_urn'] ?? null) : null;

        return is_string($postedAs) && $this->hasPage($postedAs) ? $postedAs : $this->defaultActor();
    }

    /** community.page, else the first selected page. */
    private function defaultActor(): string
    {
        $configured = self::configuredPage();

        if ($configured !== null) {
            return $this->page($configured)->id;
        }

        return $this->connection->selectedPageIds()[0] ?? $this->connection->authorUrn;
    }

    private function organization(?string $organization): string
    {
        $page = $organization === null
            ? ($this->defaultPage() ?? throw new InvalidArgumentException('This LinkedIn connection has no LinkedIn Page; set LINKEDIN_ORGANIZATIONS=true and connect again.'))
            : $this->page($organization);

        if ($page->type !== Page::TYPE_ORGANIZATION) {
            throw new InvalidArgumentException("[{$organization}] is not a LinkedIn Page (organization).");
        }

        return $page->id;
    }

    private function page(string $id): Page
    {
        return $this->findPage($id) ?? throw new InvalidArgumentException("[{$id}] is not a LinkedIn page of this connection.");
    }

    private function hasPage(string $id): bool
    {
        return $this->findPage($id) !== null;
    }

    private function findPage(string $id): ?Page
    {
        foreach ($this->connection->availablePages() as $page) {
            if ($page->id === $id) {
                return $page;
            }
        }

        return null;
    }

    /** community.page as a URN: "2414183" means urn:li:organization:2414183. */
    private static function configuredPage(): ?string
    {
        $page = config('linkedin-autopost.community.page');
        $page = is_scalar($page) ? trim((string) $page) : '';

        if ($page === '') {
            return null;
        }

        return str_starts_with($page, 'urn:li:') ? $page : 'urn:li:organization:'.$page;
    }

    /** LinkedIn caps page sizes at 100. */
    private static function count(int $count): int
    {
        return max(1, min(100, $count));
    }
}
