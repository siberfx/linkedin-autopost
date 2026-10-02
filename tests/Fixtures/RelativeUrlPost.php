<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Siberfx\LinkedInAutopost\Contracts\ShareableOnLinkedIn;
use Siberfx\LinkedInAutopost\Posts\LinkPost;

/** A live model whose toLinkedInPost() throws: it builds a relative URL. */
final class RelativeUrlPost extends Model implements ShareableOnLinkedIn
{
    protected $table = 'posts';

    protected $guarded = [];

    public function isLiveForLinkedIn(): bool
    {
        return true;
    }

    public function toLinkedInPost(): LinkPost
    {
        return LinkPost::make('/posts/'.$this->getKey(), (string) $this->title);
    }
}
