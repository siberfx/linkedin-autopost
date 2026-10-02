<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Exceptions;

use RuntimeException;

final class NotShareable extends RuntimeException
{
    public function __construct(string $message = 'This item is not live, so it cannot be shared on LinkedIn.')
    {
        parent::__construct($message);
    }
}
