<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Liberu\Cms\Core\Tests\TestCase;

pest()->extend(TestCase::class)->beforeEach(function () {
    Schema::create('teams', function (Blueprint $table) {
        $table->id();
    });
    $this->artisan('migrate')->assertSuccessful();
})->in('Feature');
