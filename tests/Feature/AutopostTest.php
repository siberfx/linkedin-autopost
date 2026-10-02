<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Siberfx\LinkedInAutopost\Contracts\TokenStore;
use Siberfx\LinkedInAutopost\Data\StoredConnection;
use Siberfx\LinkedInAutopost\Events\ShareFailed;
use Siberfx\LinkedInAutopost\Jobs\ShareOnLinkedIn;
use Siberfx\LinkedInAutopost\LinkedInManager;
use Siberfx\LinkedInAutopost\Models\LinkedInPost;
use Siberfx\LinkedInAutopost\Tests\Fixtures\Post;
use Siberfx\LinkedInAutopost\Tests\Fixtures\PostStatus;

beforeEach(function () {
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:5'])]);
    config(['linkedin-autopost.autopost.enabled' => true]);
    $this->pretendNotInConsole();
    app(TokenStore::class)->put(new StoredConnection('member-token', 'urn:li:person:abc', connectedAt: CarbonImmutable::now()));
});

it('queues a share when a model is created live', function () {
    Queue::fake();

    $post = Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]);

    Queue::assertPushed(ShareOnLinkedIn::class, fn (ShareOnLinkedIn $job) => $job->shareableType === 'post' && $job->shareableId === $post->id);
});

it('queues a share when a draft is published', function () {
    Queue::fake();
    $post = Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Draft]);
    Queue::assertNothingPushed();

    $post->update(['status' => PostStatus::Published]);

    Queue::assertPushed(ShareOnLinkedIn::class, 1);
});

// Review Focus 5: the "before" check uses the same cast rule on the previous raw values.
it('does not queue when an already live model is edited', function () {
    $post = Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]);
    Queue::fake();

    $post->update(['title' => 'Renamed']);
    $post->refresh()->update(['title' => 'Renamed again']);

    Queue::assertNothingPushed();
});

it('does not queue when auto-post is off, not connected, already posted, or in the console', function (Closure $arrange) {
    Queue::fake();
    $arrange($this);

    Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]);

    Queue::assertNothingPushed();
})->with([
    'auto-post off' => [fn () => config(['linkedin-autopost.autopost.enabled' => false])],
    'not connected' => [fn () => app(TokenStore::class)->forget()],
    'in the console' => [fn ($test) => (fn () => $this->isRunningInConsole = true)->call(app())],
]);

it('does not queue a model that was already posted', function () {
    $post = Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Draft]);
    LinkedInPost::query()->create([
        'shareable_type' => 'post', 'shareable_id' => $post->id, 'post_urn' => 'urn:li:share:old',
        'status' => LinkedInPost::STATUS_POSTED, 'trigger' => LinkedInPost::TRIGGER_MANUAL, 'posted_at' => now(),
    ]);
    Queue::fake();

    $post->update(['status' => PostStatus::Published]);

    Queue::assertNothingPushed();
});

it('queues in the console when in_console is enabled', function () {
    Queue::fake();
    config(['linkedin-autopost.autopost.in_console' => true]);
    (fn () => $this->isRunningInConsole = true)->call(app());

    Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]);

    Queue::assertPushed(ShareOnLinkedIn::class);
});

// Review Focus 2: dispatched after commit, never for a rolled-back record.
it('does not post a save that is rolled back, and posts once the transaction commits', function () {
    // Real sync queue (not Queue::fake): it honours afterCommit, so this
    // exercises what a queue worker would actually see.
    try {
        DB::transaction(function () {
            Post::query()->create(['title' => 'Rolled back', 'status' => PostStatus::Published]);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    Http::assertNothingSent();

    DB::transaction(function () {
        Post::query()->create(['title' => 'Committed', 'status' => PostStatus::Published]);
        Http::assertNothingSent(); // not before commit
    });

    Http::assertSentCount(1);
    expect(LinkedInPost::query()->count())->toBe(1);
});

it('posts when the job runs and records the trigger as auto', function () {
    $post = Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]); // sync queue runs it

    $record = LinkedInPost::query()->for($post)->sole();

    expect($record->post_urn)->toBe('urn:li:share:5')
        ->and($record->trigger)->toBe(LinkedInPost::TRIGGER_AUTO)
        ->and($post->wasPostedToLinkedIn())->toBeTrue()
        ->and($post->linkedInPosts)->toHaveCount(1);
});

it('skips silently when the model is no longer live or gone by the time the job runs', function () {
    Http::fake();
    $post = Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Draft]);

    (new ShareOnLinkedIn('post', $post->id))->handle(app(LinkedInManager::class));
    (new ShareOnLinkedIn('post', 999))->handle(app(LinkedInManager::class));

    Http::assertNothingSent();
});

it('records a failed row and fires ShareFailed after the last attempt', function () {
    Event::fake([ShareFailed::class]);
    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));

    (new ShareOnLinkedIn('post', $post->id))->failed(new RuntimeException('LinkedIn rejected the request: boom'));

    $row = LinkedInPost::query()->for($post)->sole();
    expect($row->status)->toBe(LinkedInPost::STATUS_FAILED)
        ->and($row->trigger)->toBe(LinkedInPost::TRIGGER_AUTO)
        ->and($row->error)->toBe('LinkedIn rejected the request: boom');
    Event::assertDispatched(ShareFailed::class, fn (ShareFailed $e) => $e->shareableType === 'post' && $e->shareableId === $post->id);
});

it('takes tries and backoff from config', function () {
    config(['linkedin-autopost.job.tries' => 5, 'linkedin-autopost.job.backoff' => [10, 20]]);
    $job = new ShareOnLinkedIn('post', 1);

    expect($job->tries)->toBe(5)->and($job->backoff())->toBe([10, 20]);
});
