<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Liberu\Cms\SiteFactoryApi\Tests\Tenant;
use Liberu\Cms\SiteFactoryApi\Tests\TestCase;

pest()->extend(TestCase::class)->beforeEach(function () {
    Schema::create('teams', function (Blueprint $table) {
        $table->id();
    });
    $this->artisan('migrate')->assertSuccessful();
    $this->tenant = Tenant::create(['id' => 1]);
    Sanctum::actingAs($this->tenant, ['*'], 'sanctum');
})->in('Feature');
