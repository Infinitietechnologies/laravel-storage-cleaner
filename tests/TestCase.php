<?php

namespace InfinitieTechnologies\StorageCleaner\Tests;

use InfinitieTechnologies\StorageCleaner\StorageCleanerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            StorageCleanerServiceProvider::class,
        ];
    }
}
