<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Data;

use Carbon\CarbonImmutable;

/**
 * The connection including the access token. Passed only between the token
 * store and the manager; never serialised to a response, log or event.
 */
final readonly class StoredConnection
{
    /** @param  list<string>  $scopes */
    public function __construct(
        public string $accessToken,
        public string $authorUrn,
        public ?string $name = null,
        public ?string $email = null,
        public ?string $picture = null,
        public array $scopes = [],
        public ?CarbonImmutable $expiresAt = null,
        public ?CarbonImmutable $connectedAt = null,
    ) {}
}
