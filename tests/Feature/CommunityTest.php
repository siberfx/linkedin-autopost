<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Siberfx\LinkedInAutopost\Community\Comment;
use Siberfx\LinkedInAutopost\Community\Reaction;
use Siberfx\LinkedInAutopost\Contracts\TokenStore;
use Siberfx\LinkedInAutopost\Data\Page;
use Siberfx\LinkedInAutopost\Data\StoredConnection;
use Siberfx\LinkedInAutopost\Exceptions\LinkedInRequestFailed;
use Siberfx\LinkedInAutopost\Exceptions\NotConnected;
use Siberfx\LinkedInAutopost\Facades\LinkedIn;
use Siberfx\LinkedInAutopost\LinkedInManager;
use Siberfx\LinkedInAutopost\Models\LinkedInPost;
use Siberfx\LinkedInAutopost\OAuth\OAuthClient;

const C_MEMBER = 'urn:li:person:abc';
const C_ACME = 'urn:li:organization:111';
const C_POST = 'urn:li:share:7001';
const C_POST_ENC = 'urn%3Ali%3Ashare%3A7001';
const C_ACME_ENC = 'urn%3Ali%3Aorganization%3A111';

function communityConnect(array $selected = [C_MEMBER]): void
{
    app(TokenStore::class)->put(new StoredConnection(
        accessToken: 'member-token',
        authorUrn: C_MEMBER,
        name: 'Ada',
        expiresAt: CarbonImmutable::now()->addDays(50),
        connectedAt: CarbonImmutable::now(),
        pages: [
            new Page(C_MEMBER, Page::TYPE_MEMBER, 'Ada'),
            new Page(C_ACME, Page::TYPE_ORGANIZATION, 'Acme'),
        ],
        selectedPages: $selected,
    ));
    app()->forgetScopedInstances();
    LinkedIn::clearResolvedInstance(LinkedInManager::class);
}

function acmeRecord(): LinkedInPost
{
    return LinkedInPost::query()->create([
        'shareable_type' => 'post',
        'shareable_id' => 1,
        'post_urn' => C_POST,
        'author_urn' => C_ACME,
        'status' => LinkedInPost::STATUS_POSTED,
        'trigger' => LinkedInPost::TRIGGER_MANUAL,
        'posted_at' => now(),
    ]);
}

it('asks for the community scopes only when community is enabled', function () {
    $user = new GenericUser(['id' => 1]);
    $scope = function () use ($user) {
        $location = $this->actingAs($user)->get('/linkedin/redirect')->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query['scope'];
    };

    expect($scope())->toBe('openid profile email w_member_social');

    config(['linkedin-autopost.community.enabled' => true]);

    expect($scope())->toBe('openid profile email w_member_social r_organization_social rw_organization_admin');
});

it('needs a connection', function () {
    LinkedIn::community();
})->throws(NotConnected::class);

it('lists the comments of a post', function () {
    communityConnect();
    Http::fake([
        'https://api.linkedin.com/rest/socialActions/*' => Http::response(['elements' => [[
            'id' => '6801',
            'commentUrn' => 'urn:li:comment:(urn:li:activity:1,6801)',
            'actor' => 'urn:li:person:zzz',
            'object' => C_POST,
            'message' => ['text' => 'Great read!'],
            'created' => ['actor' => 'urn:li:person:zzz', 'time' => 1_790_000_000_000],
        ]]]),
    ]);

    $comments = LinkedIn::community()->comments(C_POST, count: 500);

    expect($comments)->toHaveCount(1)
        ->and($comments[0])->toBeInstanceOf(Comment::class)
        ->and($comments[0]->id)->toBe('6801')
        ->and($comments[0]->text)->toBe('Great read!')
        ->and($comments[0]->actor)->toBe('urn:li:person:zzz')
        ->and($comments[0]->createdAt?->getTimestampMs())->toBe(1_790_000_000_000);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.linkedin.com/rest/socialActions/'.C_POST_ENC.'/comments?count=100&start=0'
        && $request->hasHeader('Authorization', 'Bearer member-token')
        && $request->hasHeader('LinkedIn-Version', '202609')
        && $request->hasHeader('X-Restli-Protocol-Version', '2.0.0'));
});

it('comments as the page the record was posted to', function () {
    communityConnect();
    Http::fake([
        'https://api.linkedin.com/rest/socialActions/*' => Http::response(['id' => '6900', 'message' => ['text' => 'Thanks!']], 201),
    ]);

    $comment = LinkedIn::community()->comment(acmeRecord(), 'Thanks!');

    expect($comment->id)->toBe('6900')->and($comment->actor)->toBe(C_ACME);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://api.linkedin.com/rest/socialActions/'.C_POST_ENC.'/comments'
        && $request['actor'] === C_ACME
        && $request['object'] === C_POST
        && $request['message'] === ['text' => 'Thanks!']
        && ! isset($request['parentComment']));
});

it('replies to a comment', function () {
    communityConnect();
    Http::fake(['*' => Http::response(['id' => '6901'], 201)]);
    $parent = Comment::fromApi(['id' => '6801', 'commentUrn' => 'urn:li:comment:(urn:li:activity:1,6801)']);

    LinkedIn::community()->comment(C_POST, 'Indeed', actor: C_ACME, replyTo: $parent);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/socialActions/'.rawurlencode('urn:li:comment:(urn:li:activity:1,6801)').'/comments')
        && $request['parentComment'] === 'urn:li:comment:(urn:li:activity:1,6801)'
        && $request['object'] === C_POST);
});

it('refuses an actor that is not one of the connection pages, and empty comments', function () {
    communityConnect();
    Http::fake();

    expect(fn () => LinkedIn::community()->comment(C_POST, 'Hi', actor: 'urn:li:organization:999'))
        ->toThrow(InvalidArgumentException::class, 'is not a LinkedIn page of this connection')
        ->and(fn () => LinkedIn::community()->comment(C_POST, '   '))
        ->toThrow(InvalidArgumentException::class, 'needs some text');

    Http::assertNothingSent();
});

it('deletes a comment as the actor', function () {
    communityConnect([C_ACME]);
    Http::fake(['*' => Http::response(null, 204)]);

    LinkedIn::community()->deleteComment(C_POST, '6801');

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.linkedin.com/rest/socialActions/'.C_POST_ENC.'/comments/6801?actor='.C_ACME_ENC);
});

it('reacts and removes a reaction', function () {
    communityConnect();
    Http::fake(['*' => Http::response(null, 201)]);

    LinkedIn::community()->react(C_POST, 'praise', actor: C_ACME);
    LinkedIn::community()->unreact(C_POST, actor: C_ACME);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://api.linkedin.com/rest/reactions?actor='.C_ACME_ENC
        && $request['root'] === C_POST
        && $request['reactionType'] === 'PRAISE');

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.linkedin.com/rest/reactions/(actor:'.C_ACME_ENC.',entity:'.C_POST_ENC.')');
});

it('refuses an unknown reaction type', function () {
    communityConnect();

    LinkedIn::community()->react(C_POST, 'ANGRY');
})->throws(InvalidArgumentException::class, 'is not a LinkedIn reaction');

it('lists reactions', function () {
    communityConnect();
    Http::fake(['https://api.linkedin.com/rest/reactions/*' => Http::response(['elements' => [
        ['reactionType' => 'EMPATHY', 'root' => C_POST, 'created' => ['actor' => 'urn:li:person:zzz', 'time' => 1_790_000_000_000]],
    ]])]);

    $reactions = LinkedIn::community()->reactions(C_POST);

    expect($reactions)->toHaveCount(1)
        ->and($reactions[0]->type)->toBe(Reaction::EMPATHY)
        ->and($reactions[0]->actor)->toBe('urn:li:person:zzz');

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.linkedin.com/rest/reactions/(entity:'.C_POST_ENC.')?q=entity'));
});

it('summarises reactions and comments of a post', function () {
    communityConnect();
    Http::fake(['https://api.linkedin.com/rest/socialMetadata/*' => Http::response([
        'reactionSummaries' => [
            'LIKE' => ['reactionType' => 'LIKE', 'count' => 12],
            'PRAISE' => ['reactionType' => 'PRAISE', 'count' => 3],
        ],
        'commentSummary' => ['count' => 7, 'topLevelCount' => 5],
        'commentsState' => 'OPEN',
    ])]);

    $summary = LinkedIn::community()->summary(acmeRecord());

    expect($summary->toArray())->toBe([
        'reactions' => ['LIKE' => 12, 'PRAISE' => 3],
        'total_reactions' => 15,
        'comments' => 7,
        'top_level_comments' => 5,
        'comments_open' => true,
    ]);
});

it('lists the recent posts of a page', function () {
    communityConnect();
    Http::fake(['https://api.linkedin.com/rest/posts*' => Http::response(['elements' => [['id' => C_POST, 'author' => C_ACME]]])]);

    expect(LinkedIn::community()->posts(C_ACME, 5))->toBe([['id' => C_POST, 'author' => C_ACME]]);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.linkedin.com/rest/posts?q=author&author='.C_ACME_ENC.'&count=5&start=0&sortBy=LAST_MODIFIED'
        && $request->hasHeader('X-RestLi-Method', 'FINDER'));
});

it('reads one post, null when LinkedIn no longer has it', function () {
    communityConnect();
    Http::fake([
        'https://api.linkedin.com/rest/posts/'.C_POST_ENC => Http::response(['id' => C_POST, 'commentary' => 'Hi']),
        'https://api.linkedin.com/rest/posts/*' => Http::response(['message' => 'Not found'], 404),
    ]);

    expect(LinkedIn::community()->post(C_POST))->toBe(['id' => C_POST, 'commentary' => 'Hi'])
        ->and(LinkedIn::community()->post('urn:li:share:404'))->toBeNull();
});

it('deletes a post and marks its record deleted', function () {
    communityConnect();
    Http::fake(['*' => Http::response(null, 204)]);
    $record = acmeRecord();

    LinkedIn::community()->deletePost($record);

    expect($record->fresh()?->status)->toBe(LinkedInPost::STATUS_DELETED);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.linkedin.com/rest/posts/'.C_POST_ENC
        && $request->hasHeader('X-RestLi-Method', 'DELETE'));
});

it('refuses a record without a post id', function () {
    communityConnect();
    $record = acmeRecord();
    $record->post_urn = null;

    LinkedIn::community()->summary($record);
})->throws(InvalidArgumentException::class, 'has no LinkedIn post id');

it('reads lifetime statistics of a page', function () {
    communityConnect();
    Http::fake([
        'https://api.linkedin.com/rest/networkSizes/*' => Http::response(['firstDegreeSize' => 1520]),
        'https://api.linkedin.com/rest/organizationalEntityShareStatistics*' => Http::response(['elements' => [[
            'organizationalEntity' => C_ACME,
            'totalShareStatistics' => [
                'impressionCount' => 1000, 'uniqueImpressionsCount' => 800, 'clickCount' => 40,
                'likeCount' => 30, 'commentCount' => 20, 'shareCount' => 10,
            ],
        ]]]),
    ]);

    $stats = LinkedIn::community()->statistics(C_ACME);

    expect($stats->toArray())->toBe([
        'organization' => C_ACME,
        'followers' => 1520,
        'impressions' => 1000,
        'unique_impressions' => 800,
        'clicks' => 40,
        'likes' => 30,
        'comments' => 20,
        'shares' => 10,
        'engagement' => 0.1,
    ]);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.linkedin.com/rest/networkSizes/'.C_ACME_ENC.'?edgeType=COMPANY_FOLLOWED_BY_MEMBER');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.linkedin.com/rest/organizationalEntityShareStatistics?q=organizationalEntity&organizationalEntity='.C_ACME_ENC);
});

it('sums daily statistics over a range', function () {
    communityConnect();
    $day = ['impressionCount' => 100, 'clickCount' => 5];
    Http::fake([
        'https://api.linkedin.com/rest/networkSizes/*' => Http::response(['firstDegreeSize' => 10]),
        'https://api.linkedin.com/rest/organizationalEntityShareStatistics*' => Http::response(['elements' => [
            ['totalShareStatistics' => $day],
            ['totalShareStatistics' => $day],
        ]]),
    ]);
    $from = CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC');
    $to = CarbonImmutable::parse('2026-11-03 00:00:00', 'UTC');

    $stats = LinkedIn::community()->statistics(C_ACME, $from, $to);

    expect($stats->impressions)->toBe(200)->and($stats->clicks)->toBe(10)->and($stats->engagement)->toBe(0.05);
    Http::assertSent(fn (Request $request) => str_contains($request->url(),
        '&timeIntervals=(timeRange:(start:'.$from->getTimestampMs().',end:'.$to->getTimestampMs().'),timeGranularityType:DAY)'));
});

it('only reads statistics of an organization page', function () {
    communityConnect();

    LinkedIn::community()->followers(C_MEMBER);
})->throws(InvalidArgumentException::class, 'is not a LinkedIn Page');

it('throws LinkedIn errors and marks the connection expired on 401', function () {
    communityConnect();
    Http::fake([
        'https://api.linkedin.com/rest/socialMetadata/*' => Http::response(['message' => 'Expired token'], 401),
        'https://www.linkedin.com/oauth/v2/introspectToken' => Http::response(['active' => true, 'status' => 'active']),
        'https://api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abc']),
    ]);

    expect(fn () => LinkedIn::community()->summary(C_POST))
        ->toThrow(LinkedInRequestFailed::class, 'Expired token');

    expect(LinkedIn::connection()->status)->toBe('expired');
});

it('turns a network failure into LinkedInRequestFailed', function () {
    communityConnect();
    Http::fake(fn () => throw new ConnectionException('timed out'));

    LinkedIn::community()->comments(C_POST);
})->throws(LinkedInRequestFailed::class, 'Could not reach LinkedIn');

it('works on the configured community page by default', function () {
    communityConnect();
    config(['linkedin-autopost.community.page' => '111']);
    Http::fake([
        'https://api.linkedin.com/rest/networkSizes/*' => Http::response(['firstDegreeSize' => 42]),
        'https://api.linkedin.com/rest/posts*' => Http::response(['elements' => []]),
        'https://api.linkedin.com/rest/reactions*' => Http::response(null, 201),
    ]);

    $community = LinkedIn::community();

    expect($community->defaultPage()?->id)->toBe(C_ACME)
        ->and($community->followers())->toBe(42);

    $community->posts();
    $community->react(C_POST);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/rest/posts?q=author&author='.C_ACME_ENC));
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.linkedin.com/rest/reactions?actor='.C_ACME_ENC);
});

it('falls back to the first LinkedIn Page, and refuses a configured page the connection does not have', function () {
    communityConnect();

    expect(LinkedIn::community()->defaultPage()?->id)->toBe(C_ACME);

    config(['linkedin-autopost.community.page' => 'urn:li:organization:999']);

    expect(fn () => LinkedIn::community()->defaultPage())
        ->toThrow(InvalidArgumentException::class, 'is not a LinkedIn page of this connection');
});

it('needs a LinkedIn Page for statistics when none is given', function () {
    app(TokenStore::class)->put(new StoredConnection(accessToken: 'member-token', authorUrn: C_MEMBER, expiresAt: CarbonImmutable::now()->addDays(50)));
    app()->forgetScopedInstances();
    LinkedIn::clearResolvedInstance(LinkedInManager::class);

    expect(LinkedIn::community()->defaultPage())->toBeNull();

    LinkedIn::community()->statistics();
})->throws(InvalidArgumentException::class, 'has no LinkedIn Page');

it('keeps only the configured organizations when connecting', function (mixed $only) {
    config([
        'linkedin-autopost.organizations.enabled' => true,
        'linkedin-autopost.organizations.only' => $only,
    ]);
    Http::fake([
        'https://api.linkedin.com/rest/organizationAcls*' => Http::response(['elements' => [
            ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => C_ACME],
            ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => 'urn:li:organization:222'],
        ]]),
        'https://api.linkedin.com/rest/organizations/*' => Http::response(['localizedName' => 'Acme']),
    ]);

    $pages = app(OAuthClient::class)->organizations('tok');

    expect(array_map(fn (Page $page) => $page->id, $pages))->toBe([C_ACME]);
})->with([
    'ids as a string' => ' 111 , 333',
    'URNs in an array' => [[C_ACME]],
]);
