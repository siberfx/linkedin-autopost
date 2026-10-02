<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Tests;

abstract class RoutesDisabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('linkedin-autopost.routes.enabled', false);
    }
}
