<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

final class LinkedInRequestFailed extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response): self
    {
        $reason = $response->json('message')
            ?? $response->json('error_description')
            ?? $response->json('error');

        $reason = is_string($reason) && $reason !== '' ? $reason : 'HTTP '.$response->status();

        return new self('LinkedIn rejected the request: '.$reason, $response->status());
    }

    public function isUnauthorized(): bool
    {
        return $this->status === 401;
    }
}
