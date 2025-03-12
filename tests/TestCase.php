<?php

namespace GoCPA\SpaceHealthcheck\Tests;

use GoCPA\SpaceHealthcheck\SpaceHealthcheckServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            SpaceHealthcheckServiceProvider::class,
        ];
    }
}
