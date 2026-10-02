<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class Post extends Model
{
    protected $guarded = [];

    protected $casts = ['status' => PostStatus::class];
}
