<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Support;

final class Ui
{
    /** False in headless mode: no views or components, JSON-only responses. */
    public static function enabled(): bool
    {
        return (bool) config('linkedin-autopost.ui.enabled', true);
    }
}
