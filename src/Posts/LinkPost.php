<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Posts;

use InvalidArgumentException;

/** What a model wants posted: a link with its preview, plus the post text. */
final class LinkPost
{
    private const DEFAULT_COMMENTARY = "{title}\n\n{url}";

    private string $description = '';

    private string $commentary = self::DEFAULT_COMMENTARY;

    private function __construct(
        private readonly string $url,
        private readonly string $title,
    ) {}

    public static function make(string $url, string $title): self
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException("LinkedIn posts need an absolute http(s) URL, got [{$url}].");
        }

        return new self($url, $title);
    }

    public function description(string $description): self
    {
        $copy = clone $this;
        $copy->description = $description;

        return $copy;
    }

    /** Post text; {url} and {title} are filled in. Reserved characters are escaped when sending. */
    public function commentary(string $template): self
    {
        $copy = clone $this;
        $copy->commentary = $template;

        return $copy;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function title(): string
    {
        return mb_substr($this->title, 0, 200);
    }

    public function descriptionText(): string
    {
        return mb_substr($this->description, 0, 256);
    }

    public function commentaryText(): string
    {
        return strtr($this->commentary, ['{url}' => $this->url, '{title}' => $this->title]);
    }
}
