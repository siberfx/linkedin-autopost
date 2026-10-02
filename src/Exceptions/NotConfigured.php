<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Exceptions;

use RuntimeException;

final class NotConfigured extends RuntimeException
{
    public function __construct(string $message = 'Set LINKEDIN_CLIENT_ID and LINKEDIN_CLIENT_SECRET before connecting LinkedIn.')
    {
        parent::__construct($message);
    }
}
