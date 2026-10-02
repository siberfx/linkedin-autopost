<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Siberfx\LinkedInAutopost\Facades\LinkedIn;
use Siberfx\LinkedInAutopost\Tests\Fixtures\ArrayTokenStore;
use Siberfx\LinkedInAutopost\Tests\Fixtures\Post;
use Siberfx\LinkedInAutopost\Tests\Fixtures\PostStatus;

beforeEach(function () {
    ArrayTokenStore::$connection = null;
    config(['linkedin-autopost.token_store' => ArrayTokenStore::class]);
    app()->forgetScopedInstances();
});

it('connects, shares and disconnects through a custom store', function () {
    Http::fake([
        'https://www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'tok', 'expires_in' => 100]),
        'https://api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abc', 'name' => 'Ada']),
        'https://api.linkedin.com/rest/posts' => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:1']),
        'https://www.linkedin.com/oauth/v2/revoke' => Http::response('', 200),
    ]);

    LinkedIn::completeConnection('code');
    expect(ArrayTokenStore::$connection?->accessToken)->toBe('tok')
        ->and(DB::table('linkedin_connections')->count())->toBe(0);

    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));
    expect(LinkedIn::share($post)->post_urn)->toBe('urn:li:share:1');

    LinkedIn::disconnect();
    expect(ArrayTokenStore::$connection)->toBeNull();
});
