<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Events;

final class Disconnected
{
    /** @param  bool  $revoked  Whether LinkedIn confirmed revoking the token. */
    public function __construct(public readonly bool $revoked) {}
}
