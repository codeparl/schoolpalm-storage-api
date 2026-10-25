<?php

namespace SchoolPalm\StorageApi\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SchoolPalm\StorageApi\StorageApiServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [StorageApiServiceProvider::class];
    }
}
