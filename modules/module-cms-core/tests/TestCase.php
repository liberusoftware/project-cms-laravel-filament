<?php

namespace Liberu\Cms\Core\Tests;

use Liberu\Cms\Core\CmsCoreServiceProvider;
use Liberu\PackageTestbench\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    protected function getPackageProviders($app): array
    {
        return [CmsCoreServiceProvider::class];
    }
}
