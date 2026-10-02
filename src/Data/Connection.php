<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Data;

use Carbon\CarbonImmutable;

/** The connection as shown to people and APIs. Never carries the token. */
final readonly class Connection
{
    /** @param  list<string>  $scopes */
    public function __construct(
        public bool $connected,
        public ?string $status = null,
        public ?string $name = null,
        public ?string $email = null,
        public ?string $picture = null,
        public ?string $authorUrn = null,
        public array $scopes = [],
        public ?CarbonImmutable $connectedAt = null,
        public ?CarbonImmutable $expiresAt = null,
    ) {}

    public static function disconnected(): self
    {
        return new self(connected: false);
    }

    public static function fromStored(StoredConnection $stored, string $status): self
    {
        return new self(
            connected: true,
            status: $status,
            name: $stored->name,
            email: $stored->email,
            picture: $stored->picture,
            authorUrn: $stored->authorUrn,
            scopes: $stored->scopes,
            connectedAt: $stored->connectedAt,
            expiresAt: $stored->expiresAt,
        );
    }

    public function withStatus(string $status): self
    {
        return new self($this->connected, $status, $this->name, $this->email, $this->picture,
            $this->authorUrn, $this->scopes, $this->connectedAt, $this->expiresAt);
    }

    /** Whole days until the token expires (negative once expired), or null when unknown. */
    public function daysLeft(): ?int
    {
        if ($this->expiresAt === null) {
            return null;
        }

        return (int) floor((float) CarbonImmutable::now()->diffInSeconds($this->expiresAt, false) / 86400);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'connected' => $this->connected,
            'status' => $this->status,
            'name' => $this->name,
            'email' => $this->email,
            'picture' => $this->picture,
            'author_urn' => $this->authorUrn,
            'scopes' => $this->scopes,
            'connected_at' => $this->connectedAt?->toIso8601String(),
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'days_left' => $this->daysLeft(),
        ];
    }
}
