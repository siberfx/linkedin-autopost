<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Tests;

abstract class HeadlessTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('linkedin-autopost.ui.enabled', false);
    }
}
