<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Events;

use Siberfx\LinkedInAutopost\Data\Connection;

final class Connected
{
    public function __construct(public readonly Connection $connection) {}
}
