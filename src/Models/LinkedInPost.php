<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Siberfx\LinkedInAutopost\Support\Columns;

/**
 * @property string $shareable_type
 * @property int|string $shareable_id
 * @property string|null $post_urn
 * @property string|null $author_urn The page the post went to; null for posts made before 1.2.
 * @property string $status
 * @property string $trigger
 * @property string|null $error
 * @property CarbonImmutable|null $posted_at
 */
final class LinkedInPost extends Model
{
    public const STATUS_POSTED = 'posted';

    public const STATUS_FAILED = 'failed';

    /** Posted, then deleted from LinkedIn through LinkedIn::community()->deletePost(). */
    public const STATUS_DELETED = 'deleted';

    public const TRIGGER_AUTO = 'auto';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_CONSOLE = 'console';

    protected $table = 'linkedin_posts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['posted_at' => 'immutable_datetime'];
    }

    /** @return MorphTo<Model, $this> */
    public function shareable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFor(Builder $query, Model $model): Builder
    {
        return $query->where('shareable_type', $model->getMorphClass())
            ->where('shareable_id', $model->getKey());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    /**
     * Posts that went to one page (author URN). Posts made before 1.2 have no
     * page and count as the member's profile, where they all went.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForPage(Builder $query, string $page, ?string $memberUrn = null): Builder
    {
        if (! Columns::has($this->getTable(), 'author_urn')) {
            return $query;
        }

        return $query->where(fn (Builder $query) => $query->where('author_urn', $page)
            ->when($page === $memberUrn, fn (Builder $query) => $query->orWhereNull('author_urn')));
    }
}
