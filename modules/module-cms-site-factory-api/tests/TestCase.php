<?php

namespace Liberu\Cms\SiteFactoryApi\Tests;

use Laravel\Sanctum\SanctumServiceProvider;
use Liberu\Cms\Api\CmsApiServiceProvider;
use Liberu\Cms\Core\CmsCoreServiceProvider;
use Liberu\Cms\SiteFactory\SiteFactoryServiceProvider;
use Liberu\Cms\SiteFactoryApi\SiteFactoryApiServiceProvider;
use Liberu\PackageTestbench\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    protected function getPackageProviders($app): array
    {
        return [CmsCoreServiceProvider::class, SanctumServiceProvider::class, CmsApiServiceProvider::class, SiteFactoryServiceProvider::class, SiteFactoryApiServiceProvider::class];
    }
}
