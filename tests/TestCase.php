<?php

namespace Rushing\Surgeon\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\Surgeon\SurgeonServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SurgeonServiceProvider::class,
        ];
    }
}
