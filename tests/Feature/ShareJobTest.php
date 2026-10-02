<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Siberfx\LinkedInAutopost\Contracts\TokenStore;
use Siberfx\LinkedInAutopost\Data\StoredConnection;
use Siberfx\LinkedInAutopost\Events\ShareFailed;
use Siberfx\LinkedInAutopost\Exceptions\LinkedInRequestFailed;
use Siberfx\LinkedInAutopost\Facades\LinkedIn;
use Siberfx\LinkedInAutopost\Jobs\ShareOnLinkedIn;
use Siberfx\LinkedInAutopost\LinkedInManager;
use Siberfx\LinkedInAutopost\Models\LinkedInPost;
use Siberfx\LinkedInAutopost\Tests\Fixtures\Post;
use Siberfx\LinkedInAutopost\Tests\Fixtures\PostStatus;
use Siberfx\LinkedInAutopost\Tests\Fixtures\RelativeUrlPost;

beforeEach(function () {
    Event::fake([ShareFailed::class]);
    app(TokenStore::class)->put(new StoredConnection('member-token', 'urn:li:person:abc', connectedAt: CarbonImmutable::now()));
    $this->post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));
});

it('fails at once, without retrying, when LinkedIn refuses the request', function (int $status) {
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response(['message' => 'No'], $status)]);

    dispatch(new ShareOnLinkedIn('post', $this->post->id)); // sync queue: the job is marked failed, nothing is thrown

    $row = LinkedInPost::query()->for($this->post)->sole();
    expect($row->status)->toBe(LinkedInPost::STATUS_FAILED)
        ->and($row->error)->toBe('LinkedIn rejected the request: No');
    Http::assertSentCount(1);
    Event::assertDispatchedTimes(ShareFailed::class, 1);
})->with(['unauthorized' => 401, 'forbidden' => 403, 'unprocessable' => 422]);

it('fails at once when the model builds an invalid post', function () {
    Http::fake();
    $post = RelativeUrlPost::query()->create(['title' => 'Bad', 'status' => 'published']);

    dispatch(new ShareOnLinkedIn(RelativeUrlPost::class, $post->id));

    expect(LinkedInPost::query()->where('status', LinkedInPost::STATUS_FAILED)->count())->toBe(1);
    Http::assertNothingSent();
    Event::assertDispatchedTimes(ShareFailed::class, 1);
});

it('rethrows failures that may succeed later, so the queue retries them', function (int $status) {
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response(['message' => 'Busy'], $status)]);

    expect(fn () => (new ShareOnLinkedIn('post', $this->post->id))->handle(app(LinkedInManager::class)))
        ->toThrow(LinkedInRequestFailed::class, 'LinkedIn rejected the request: Busy');
    expect(LinkedInPost::query()->count())->toBe(0);
    Event::assertNotDispatched(ShareFailed::class);
})->with(['rate limited' => 429, 'server error' => 500, 'unavailable' => 503]);

it('rethrows a network failure for retry', function () {
    Http::fake(['https://api.linkedin.com/rest/posts' => fn () => throw new ConnectionException('timed out')]);

    expect(fn () => (new ShareOnLinkedIn('post', $this->post->id))->handle(app(LinkedInManager::class)))
        ->toThrow(LinkedInRequestFailed::class, 'Could not reach LinkedIn: timed out');
    Event::assertNotDispatched(ShareFailed::class);
});

it('is unique per shareable while queued', function () {
    $job = new ShareOnLinkedIn('post', 42);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('post:42')
        ->and($job->uniqueFor)->toBe(600);
});

it('does not queue a second share for the same model while one is waiting', function () {
    Queue::fake();
    $other = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Other', 'status' => PostStatus::Published]));

    LinkedIn::queue($this->post);
    LinkedIn::queue($this->post);
    LinkedIn::queue($other);

    Queue::assertPushed(ShareOnLinkedIn::class, 2);
});
