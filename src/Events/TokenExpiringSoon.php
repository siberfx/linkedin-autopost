<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Events;

use Siberfx\LinkedInAutopost\Data\Connection;

final class TokenExpiringSoon
{
    /** @param  int  $daysLeft  Negative once the token has expired. */
    public function __construct(
        public readonly Connection $connection,
        public readonly int $daysLeft,
    ) {}
}
