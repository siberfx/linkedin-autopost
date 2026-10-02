<?php

declare(strict_types=1);

it('merges the package config with safe defaults', function () {
    expect(config('linkedin-autopost.autopost.enabled'))->toBeFalse()
        ->and(config('linkedin-autopost.autopost.in_console'))->toBeFalse()
        ->and(config('linkedin-autopost.api_version'))->toBe('202609')
        ->and(config('linkedin-autopost.scopes'))->toBe(['openid', 'profile', 'email', 'w_member_social'])
        ->and(config('linkedin-autopost.routes.prefix'))->toBe('linkedin');
});

it('creates the package tables', function () {
    expect(Schema::hasTable('linkedin_connections'))->toBeTrue()
        ->and(Schema::hasTable('linkedin_posts'))->toBeTrue();
});
