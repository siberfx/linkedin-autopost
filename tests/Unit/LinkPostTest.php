<?php

declare(strict_types=1);

use Siberfx\LinkedInAutopost\Posts\LinkPost;

it('builds the default commentary from title and url', function () {
    $post = LinkPost::make('https://example.com/blog/hello', 'Hello world');

    expect($post->commentaryText())->toBe("Hello world\n\nhttps://example.com/blog/hello")
        ->and($post->descriptionText())->toBe('');
});

it('fills {url} and {title} in a custom commentary', function () {
    $post = LinkPost::make('https://example.com/x', 'X')
        ->commentary('New: {title} → {url} #launch');

    expect($post->commentaryText())->toBe('New: X → https://example.com/x #launch');
});

it('is immutable', function () {
    $base = LinkPost::make('https://example.com/x', 'X');
    $changed = $base->description('changed');

    expect($base->descriptionText())->toBe('')
        ->and($changed->descriptionText())->toBe('changed');
});

it('limits title to 200 and description to 256 characters', function () {
    $post = LinkPost::make('https://example.com/x', str_repeat('ü', 300))
        ->description(str_repeat('é', 400));

    expect(mb_strlen($post->title()))->toBe(200)
        ->and(mb_strlen($post->descriptionText()))->toBe(256);
});

it('rejects a relative or non-http url', function (string $url) {
    LinkPost::make($url, 'X');
})->with(['/blog/x', 'ftp://example.com/x', 'javascript:alert(1)', ''])
    ->throws(InvalidArgumentException::class);
