<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Siberfx\LinkedInAutopost\Models\LinkedInPost;
use Siberfx\LinkedInAutopost\Observers\ShareableObserver;

/** For models implementing ShareableOnLinkedIn: auto-post and share history. */
trait PostsToLinkedIn
{
    public static function bootPostsToLinkedIn(): void
    {
        // Not static::observe(): that instantiates the model, which throws
        // while the model is still booting.
        static::saved(static fn ($model) => app(ShareableObserver::class)->saved($model));
    }

    /** @return MorphMany<LinkedInPost, $this> */
    public function linkedInPosts(): MorphMany
    {
        return $this->morphMany(LinkedInPost::class, 'shareable');
    }

    public function wasPostedToLinkedIn(): bool
    {
        return $this->linkedInPosts()->where('status', LinkedInPost::STATUS_POSTED)->exists();
    }
}
