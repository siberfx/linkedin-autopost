<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Data;

use Carbon\CarbonImmutable;

/**
 * The connection including the access token. Passed only between the token
 * store and the manager; never serialised to a response, log or event.
 *
 * $pages lists where posts can go and $selectedPages the ids (author URNs)
 * they go to. Both may be empty, e.g. in a connection stored before pages
 * existed or by a token store that does not keep them: the member's own
 * profile is then the only page, and selected.
 */
final readonly class StoredConnection
{
    /**
     * @param  list<string>  $scopes
     * @param  list<Page>  $pages
     * @param  list<string>  $selectedPages
     */
    public function __construct(
        public string $accessToken,
        public string $authorUrn,
        public ?string $name = null,
        public ?string $email = null,
        public ?string $picture = null,
        public array $scopes = [],
        public ?CarbonImmutable $expiresAt = null,
        public ?CarbonImmutable $connectedAt = null,
        public array $pages = [],
        public array $selectedPages = [],
    ) {}

    /** @return list<Page> The member's profile first, then the organizations. */
    public function availablePages(): array
    {
        foreach ($this->pages as $page) {
            if ($page->id === $this->authorUrn) {
                return $this->pages;
            }
        }

        return [new Page($this->authorUrn, Page::TYPE_MEMBER, $this->name, $this->picture), ...$this->pages];
    }

    /** @return list<string> The selected ids that are still available; the member's profile when none are. */
    public function selectedPageIds(): array
    {
        $available = array_map(fn (Page $page) => $page->id, $this->availablePages());
        $selected = array_values(array_intersect(array_unique($this->selectedPages), $available));

        return $selected !== [] ? $selected : [$this->authorUrn];
    }

    /** @return list<Page> */
    public function selectedPages(): array
    {
        $ids = $this->selectedPageIds();

        return array_values(array_filter($this->availablePages(), fn (Page $page) => in_array($page->id, $ids, true)));
    }

    /** @param  list<string>  $ids */
    public function withSelectedPages(array $ids): self
    {
        return new self($this->accessToken, $this->authorUrn, $this->name, $this->email, $this->picture,
            $this->scopes, $this->expiresAt, $this->connectedAt, $this->availablePages(), array_values(array_unique($ids)));
    }
}
