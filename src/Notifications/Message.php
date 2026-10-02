<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Notifications;

use Illuminate\Database\Eloquent\Model;
use Siberfx\LinkedInAutopost\Contracts\ShareableOnLinkedIn;
use Siberfx\LinkedInAutopost\Events\Shared;
use Siberfx\LinkedInAutopost\Events\ShareFailed;
use Siberfx\LinkedInAutopost\Events\TokenExpiringSoon;
use Throwable;

/** Plain text a channel formats its own way: a headline and lines below it. Holds only strings, so it queues cleanly. */
final class Message
{
    public const LEVEL_SUCCESS = 'success';

    public const LEVEL_ERROR = 'error';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_INFO = 'info';

    /** @param  list<string>  $lines */
    public function __construct(
        public readonly string $level,
        public readonly string $headline,
        public readonly array $lines = [],
    ) {}

    public static function shared(Shared $event): self
    {
        [$title, $url] = self::describe($event->shareable);
        $urn = $event->post->post_urn;

        return new self(self::LEVEL_SUCCESS, 'Shared on LinkedIn: '.$title, array_values(array_filter([
            $url,
            $urn !== null ? 'Post: https://www.linkedin.com/feed/update/'.$urn.'/' : null,
            'Trigger: '.$event->post->trigger,
        ])));
    }

    public static function failed(ShareFailed $event): self
    {
        return new self(self::LEVEL_ERROR, 'LinkedIn share failed', [
            "Model: {$event->shareableType} #{$event->shareableId}",
            'Error: '.mb_substr($event->exception->getMessage(), 0, 500),
        ]);
    }

    public static function tokenExpiring(TokenExpiringSoon $event): self
    {
        $headline = $event->daysLeft > 0
            ? "The LinkedIn token expires in {$event->daysLeft} days"
            : 'The LinkedIn token has expired';

        return new self(self::LEVEL_WARNING, $headline, array_values(array_filter([
            $event->connection->name !== null ? 'Account: '.$event->connection->name : null,
            'Connect again to keep posting.',
        ])));
    }

    public static function test(): self
    {
        return new self(self::LEVEL_INFO, 'LinkedIn Autopost test notification', [
            'Notifications from '.config('app.name', 'Laravel').' reach this channel.',
        ]);
    }

    public function emoji(): string
    {
        return match ($this->level) {
            self::LEVEL_SUCCESS => '✅',
            self::LEVEL_ERROR => '❌',
            self::LEVEL_WARNING => '⚠️',
            default => 'ℹ️',
        };
    }

    /** @return array{0: string, 1: string|null} The title and URL the model posts, or its type and key. */
    private static function describe(Model $model): array
    {
        if ($model instanceof ShareableOnLinkedIn) {
            try {
                $post = $model->toLinkedInPost();

                return [$post->title(), $post->url()];
            } catch (Throwable) {
                // Fall through: the post already went out, the message still should.
            }
        }

        return [$model->getMorphClass().' #'.$model->getKey(), null];
    }
}
