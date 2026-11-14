<?php

namespace Liberu\Cms\SiteFactoryApi\Tests;

use Illuminate\Foundation\Auth\User;
use Laravel\Sanctum\HasApiTokens;

class Tenant extends User
{
    use HasApiTokens;

    protected $table = 'teams';

    protected $guarded = [];

    public $timestamps = false;
}
