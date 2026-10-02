<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Events;

use Illuminate\Database\Eloquent\Model;
use Siberfx\LinkedInAutopost\Models\LinkedInPost;

final class Shared
{
    public function __construct(
        public readonly Model $shareable,
        public readonly LinkedInPost $post,
    ) {}
}
