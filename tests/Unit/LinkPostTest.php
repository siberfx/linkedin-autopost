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
})->with(['/blog/x', 'ftp://example.com/x', 'javascript:alert(1)', '', 'blog/çalışma', '//example.com/x'])
    ->throws(InvalidArgumentException::class);

it('percent-encodes a non-ASCII path, query and fragment', function () {
    $post = LinkPost::make('https://example.com/blog/çalışma?q=ünlü #bölüm', 'X');

    expect($post->url())->toBe('https://example.com/blog/%C3%A7al%C4%B1%C5%9Fma?q=%C3%BCnl%C3%BC%20#b%C3%B6l%C3%BCm')
        ->and($post->commentaryText())->toBe("X\n\n".$post->url());
});

it('leaves an already encoded URL alone', function () {
    expect(LinkPost::make('https://example.com/blog/%C3%A7al?x=1&y=2', 'X')->url())
        ->toBe('https://example.com/blog/%C3%A7al?x=1&y=2');
});

it('converts an internationalised host to punycode', function () {
    expect(LinkPost::make('https://şirket.com.tr/x', 'X')->url())->toBe('https://xn--irket-idb.com.tr/x')
        ->and(LinkPost::make('https://user@şirket.com.tr:8443/çay', 'X')->url())->toBe('https://user@xn--irket-idb.com.tr:8443/%C3%A7ay');
})->skip(! function_exists('idn_to_ascii'), 'ext-intl is not installed');
