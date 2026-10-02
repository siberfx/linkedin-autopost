<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Siberfx\LinkedInAutopost\Contracts\ShareableOnLinkedIn;
use Siberfx\LinkedInAutopost\Posts\LinkPost;

final class Post extends Model implements ShareableOnLinkedIn
{
    protected $guarded = [];

    protected $casts = ['status' => PostStatus::class];

    public function isLiveForLinkedIn(): bool
    {
        return $this->status === PostStatus::Published;
    }

    public function toLinkedInPost(): LinkPost
    {
        return LinkPost::make('https://example.com/posts/'.$this->getKey(), (string) $this->title)
            ->description('About '.$this->title);
    }
}
