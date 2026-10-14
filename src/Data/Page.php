<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Data;

/**
 * Somewhere a post can go: the connected member's own profile, or a LinkedIn
 * Page (organization) the member administers. $id is the author URN posts are sent as.
 */
final readonly class Page
{
    public const TYPE_MEMBER = 'member';

    public const TYPE_ORGANIZATION = 'organization';

    public function __construct(
        public string $id,
        public string $type,
        public ?string $name = null,
        public ?string $picture = null,
    ) {}

    /** @param  array<mixed>  $data  What toArray() returned. Null when it is not a page. */
    public static function fromArray(array $data): ?self
    {
        $id = $data['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $string = fn (string $key): ?string => is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : null;

        return new self(
            id: $id,
            type: $string('type') ?? (str_starts_with($id, 'urn:li:organization:') ? self::TYPE_ORGANIZATION : self::TYPE_MEMBER),
            name: $string('name'),
            picture: $string('picture'),
        );
    }

    /**
     * @param  array<mixed>  $items
     * @return list<self>
     */
    public static function listFrom(array $items): array
    {
        $pages = [];

        foreach ($items as $item) {
            $page = is_array($item) ? self::fromArray($item) : null;

            if ($page !== null) {
                $pages[] = $page;
            }
        }

        return $pages;
    }

    /** @return array{id: string, type: string, name: ?string, picture: ?string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'picture' => $this->picture,
        ];
    }
}
