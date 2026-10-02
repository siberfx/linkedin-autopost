<?php

declare(strict_types=1);

use Siberfx\LinkedInAutopost\Tests\RoutesDisabledTestCase;

uses(RoutesDisabledTestCase::class);

it('registers no routes when disabled', function () {
    expect(app('router')->has('linkedin-autopost.callback'))->toBeFalse()
        ->and(app('router')->has('linkedin-autopost.share'))->toBeFalse();
});
