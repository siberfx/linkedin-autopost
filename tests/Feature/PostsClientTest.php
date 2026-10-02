<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Siberfx\LinkedInAutopost\Exceptions\LinkedInRequestFailed;
use Siberfx\LinkedInAutopost\Posts\LinkPost;
use Siberfx\LinkedInAutopost\Posts\PostsClient;

const POSTS_URL = 'https://api.linkedin.com/rest/posts';

it('creates a link post through the Posts API', function () {
    Http::fake([POSTS_URL => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:1'])]);

    $post = LinkPost::make('https://example.com/blog/q-a', 'Q&A (part_1) #2')
        ->description('Answers [draft]');

    $urn = app(PostsClient::class)->create('member-token', 'urn:li:person:abc', $post);

    expect($urn)->toBe('urn:li:share:1');

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->url() === POSTS_URL
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer member-token')
            && $request->hasHeader('X-Restli-Protocol-Version', '2.0.0')
            && $request->hasHeader('LinkedIn-Version', '202609')
            && $body['author'] === 'urn:li:person:abc'
            && $body['commentary'] === "Q&A \(part\_1\) \#2\n\nhttps://example.com/blog/q-a"
            && $body['visibility'] === 'PUBLIC'
            && $body['distribution'] === ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []]
            && $body['content']['article'] === [
                'source' => 'https://example.com/blog/q-a',
                'title' => 'Q&A (part_1) #2',
                'description' => 'Answers [draft]',
            ]
            && $body['lifecycleState'] === 'PUBLISHED'
            && $body['isReshareDisabledByAuthor'] === false;
    });
});

it('omits an empty description from the article', function () {
    Http::fake([POSTS_URL => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:2'])]);

    app(PostsClient::class)->create('t', 'urn:li:person:abc', LinkPost::make('https://example.com/x', 'X'));

    Http::assertSent(fn (Request $request) => ! array_key_exists('description', $request->data()['content']['article']));
});

it('uses the configured api version and visibility', function () {
    config(['linkedin-autopost.api_version' => '202608', 'linkedin-autopost.visibility' => 'CONNECTIONS']);
    Http::fake([POSTS_URL => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:3'])]);

    app(PostsClient::class)->create('t', 'urn:li:person:abc', LinkPost::make('https://example.com/x', 'X'));

    Http::assertSent(fn (Request $request) => $request->hasHeader('LinkedIn-Version', '202608')
        && $request->data()['visibility'] === 'CONNECTIONS');
});

it('falls back to the id in the body', function () {
    Http::fake([POSTS_URL => Http::response(['id' => 'urn:li:ugcPost:9'], 201)]);

    expect(app(PostsClient::class)->create('t', 'urn:li:person:abc', LinkPost::make('https://example.com/x', 'X')))
        ->toBe('urn:li:ugcPost:9');
});

it('reports a 401 as unauthorized with LinkedIn\'s message', function () {
    Http::fake([POSTS_URL => Http::response(['message' => 'Invalid access token', 'status' => 401], 401)]);

    try {
        app(PostsClient::class)->create('t', 'urn:li:person:abc', LinkPost::make('https://example.com/x', 'X'));
        $this->fail('Expected LinkedInRequestFailed');
    } catch (LinkedInRequestFailed $e) {
        expect($e->isUnauthorized())->toBeTrue()
            ->and($e->status)->toBe(401)
            ->and($e->getMessage())->toBe('LinkedIn rejected the request: Invalid access token');
    }
});

it('reports other errors with LinkedIn\'s message or the status', function (int $status, mixed $body, string $message) {
    Http::fake([POSTS_URL => Http::response($body, $status)]);

    expect(fn () => app(PostsClient::class)->create('t', 'urn:li:person:abc', LinkPost::make('https://example.com/x', 'X')))
        ->toThrow(LinkedInRequestFailed::class, $message);
})->with([
    'json message' => [422, ['message' => 'commentary length exceeds the allowed maximum'], 'LinkedIn rejected the request: commentary length exceeds the allowed maximum'],
    'no body' => [503, null, 'LinkedIn rejected the request: HTTP 503'],
]);

it('fails when LinkedIn returns no post id', function () {
    Http::fake([POSTS_URL => Http::response(null, 201)]);

    expect(fn () => app(PostsClient::class)->create('t', 'urn:li:person:abc', LinkPost::make('https://example.com/x', 'X')))
        ->toThrow(LinkedInRequestFailed::class, 'LinkedIn accepted the post but returned no post id.');
});

it('reports a network failure as a LinkedIn request failure', function () {
    Http::fake([POSTS_URL => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out')]);

    expect(fn () => app(PostsClient::class)->create('t', 'urn:li:person:abc', LinkPost::make('https://example.com/x', 'X')))
        ->toThrow(LinkedInRequestFailed::class, 'Could not reach LinkedIn: timed out');
});
