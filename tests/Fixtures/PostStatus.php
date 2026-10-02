<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Tests\Fixtures;

enum PostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
