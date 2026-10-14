<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Queue\InteractsWithQueue;
use InvalidArgumentException;
use Siberfx\LinkedInAutopost\Contracts\ShareableOnLinkedIn;
use Siberfx\LinkedInAutopost\Data\Page;
use Siberfx\LinkedInAutopost\Events\ShareFailed;
use Siberfx\LinkedInAutopost\Exceptions\LinkedInRequestFailed;
use Siberfx\LinkedInAutopost\Exceptions\NotConnected;
use Siberfx\LinkedInAutopost\Exceptions\NotShareable;
use Siberfx\LinkedInAutopost\LinkedInManager;
use Siberfx\LinkedInAutopost\Models\LinkedInPost;
use Throwable;

final class ShareOnLinkedIn implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    /** Seconds the unique lock may outlive a job that never ran. */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $shareableType,
        public readonly int|string $shareableId,
    ) {
        $this->tries = (int) config('linkedin-autopost.job.tries', 3);
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return array_values(array_map('intval', (array) config('linkedin-autopost.job.backoff', [60, 300])));
    }

    public function handle(LinkedInManager $linkedin): void
    {
        $model = $this->resolve();

        // Unpublished, deleted or disconnected since the job was queued: nothing to do.
        if ($model === null || ! $model->isLiveForLinkedIn() || ! $linkedin->isConnected()) {
            return;
        }

        // A retry never posts twice to a page that already has the post.
        $pending = array_values(array_filter(
            $linkedin->selectedPages(),
            fn (Page $page) => ! $linkedin->wasPosted($model, $page->id),
        ));

        $retry = null;
        $permanent = null;

        foreach ($pending as $page) {
            try {
                $linkedin->shareToPage($model, $page->id, LinkedInPost::TRIGGER_AUTO);
            } catch (LinkedInRequestFailed $e) {
                // 429, 5xx and network errors (status 0) are retried; other 4xx never succeed.
                if ($e->isPermanent()) {
                    $permanent ??= $this->onPage($page, $e);
                } else {
                    $retry ??= $e;
                }
            } catch (InvalidArgumentException|NotShareable|NotConnected $e) {
                $this->fail($e);

                return;
            }
        }

        if ($retry !== null) {
            throw $retry;
        }

        if ($permanent !== null) {
            $this->fail($permanent);
        }
    }

    /** Names the page when there are several, so the failure says where it happened. */
    private function onPage(Page $page, LinkedInRequestFailed $e): LinkedInRequestFailed
    {
        if ($page->type === Page::TYPE_MEMBER) {
            return $e;
        }

        return new LinkedInRequestFailed(($page->name ?? $page->id).': '.$e->getMessage(), $e->status);
    }

    /** One queued share per model: quick publish/unpublish/publish cycles cannot queue two posts. */
    public function uniqueId(): string
    {
        return "{$this->shareableType}:{$this->shareableId}";
    }

    public function failed(Throwable $exception): void
    {
        LinkedInPost::query()->create([
            'shareable_type' => $this->shareableType,
            'shareable_id' => $this->shareableId,
            'status' => LinkedInPost::STATUS_FAILED,
            'trigger' => LinkedInPost::TRIGGER_AUTO,
            'error' => mb_substr($exception->getMessage(), 0, 2000),
            'posted_at' => null,
            'created_at' => CarbonImmutable::now(),
        ]);

        event(new ShareFailed($this->shareableType, $this->shareableId, $exception));
    }

    private function resolve(): (Model&ShareableOnLinkedIn)|null
    {
        $class = Relation::getMorphedModel($this->shareableType) ?? $this->shareableType;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, ShareableOnLinkedIn::class)) {
            return null;
        }

        /** @var (Model&ShareableOnLinkedIn)|null */
        return $class::query()->find($this->shareableId);
    }
}
