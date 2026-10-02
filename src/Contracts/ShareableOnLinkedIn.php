<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Contracts;

use Siberfx\LinkedInAutopost\Posts\LinkPost;

/** Implemented by Eloquent models that can be shared on LinkedIn. */
interface ShareableOnLinkedIn
{
    /** Whether the model is public right now (e.g. published and not expired). */
    public function isLiveForLinkedIn(): bool;

    public function toLinkedInPost(): LinkPost;
}
