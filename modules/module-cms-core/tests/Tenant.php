<?php

namespace Liberu\Cms\Core\Tests;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $table = 'teams';

    protected $guarded = [];

    public $timestamps = false;
}
