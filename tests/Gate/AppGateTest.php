<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Http;
use Siberfx\LinkedInAutopost\Tests\AppGateTestCase;

uses(AppGateTestCase::class);

it('lets the app definition of the gate win over the package default', function () {
    Http::fake();

    $this->actingAs(new GenericUser(['id' => 1, 'name' => 'Admin']))->getJson('/linkedin/connection')
        ->assertOk()
        ->assertJsonPath('data.connected', false);
    $this->actingAs(new GenericUser(['id' => 2, 'name' => 'Editor']))->getJson('/linkedin/connection')
        ->assertForbidden();
});
