<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Exceptions;

use RuntimeException;

final class NotConnected extends RuntimeException
{
    public function __construct(string $message = 'LinkedIn is not connected.')
    {
        parent::__construct($message);
    }
}
