<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Siberfx\LinkedInAutopost\Contracts\TokenStore;
use Siberfx\LinkedInAutopost\Data\StoredConnection;
use Siberfx\LinkedInAutopost\Models\LinkedInPost;
use Siberfx\LinkedInAutopost\Tests\Fixtures\Post;
use Siberfx\LinkedInAutopost\Tests\Fixtures\PostStatus;
use Siberfx\LinkedInAutopost\Tests\Fixtures\RelativeUrlPost;

beforeEach(function () {
    $this->user = new GenericUser(['id' => 1]);
    app(TokenStore::class)->put(new StoredConnection('member-token', 'urn:li:person:abc'));
    $this->post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));
});

it('disconnects from a form and redirects back with a message', function () {
    Http::fake(['https://www.linkedin.com/oauth/v2/revoke' => Http::response('', 200)]);

    $this->actingAs($this->user)->from('/admin/settings')->delete('/linkedin/connection')
        ->assertRedirect('/admin/settings')
        ->assertSessionHas('linkedin-autopost.flash', ['type' => 'success', 'message' => 'LinkedIn disconnected.']);
});

it('shares through the signed url without a morph map entry', function () {
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:4'])]);
    $url = URL::signedRoute('linkedin-autopost.share.signed', ['type' => Post::class, 'id' => $this->post->id]);

    $this->actingAs($this->user)->from('/admin/posts')->post($url)
        ->assertRedirect('/admin/posts')
        ->assertSessionHas('linkedin-autopost.flash', ['type' => 'success', 'message' => 'Shared on LinkedIn.']);

    expect(LinkedInPost::query()->for($this->post)->posted()->value('post_urn'))->toBe('urn:li:share:4');
});

it('rejects a signed url whose type or id was changed', function () {
    Http::fake();
    $url = URL::signedRoute('linkedin-autopost.share.signed', ['type' => Post::class, 'id' => $this->post->id]);

    $this->actingAs($this->user)->post(str_replace('id='.$this->post->id, 'id=999', $url))->assertForbidden();
    $this->actingAs($this->user)->post(str_replace(urlencode(Post::class), urlencode(GenericUser::class), $url))->assertForbidden();
    Http::assertNothingSent();
});

it('flashes LinkedIn errors on a form share', function () {
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response(['message' => 'Rate limited'], 429)]);
    $url = URL::signedRoute('linkedin-autopost.share.signed', ['type' => 'post', 'id' => $this->post->id]);

    $this->actingAs($this->user)->from('/admin/posts')->post($url)
        ->assertRedirect('/admin/posts')
        ->assertSessionHas('linkedin-autopost.flash', ['type' => 'error', 'message' => 'LinkedIn rejected the request: Rate limited']);
});

it('still answers JSON to JSON requests on the signed route', function () {
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:6'])]);
    $url = URL::signedRoute('linkedin-autopost.share.signed', ['type' => 'post', 'id' => $this->post->id]);

    $this->actingAs($this->user)->postJson($url)->assertCreated()->assertJsonPath('data.post_urn', 'urn:li:share:6');
});

it('flashes an error when the model cannot build a valid LinkedIn post', function () {
    Http::fake();
    $post = RelativeUrlPost::query()->create(['title' => 'Hi', 'status' => 'published']);
    $url = URL::signedRoute('linkedin-autopost.share.signed', ['type' => RelativeUrlPost::class, 'id' => $post->id]);

    $this->actingAs($this->user)->from('/admin/posts')->post($url)
        ->assertRedirect('/admin/posts')
        ->assertSessionHas('linkedin-autopost.flash', ['type' => 'error', 'message' => 'LinkedIn posts need an absolute http(s) URL, got [/posts/'.$post->id.'].']);
    Http::assertNothingSent();
});
