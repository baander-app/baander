<?php

namespace App\Modules\Nanoid;

use Illuminate\Support\Facades\Facade;

class NanoId extends Facade
{
    protected static function getFacadeAccessor()
    {
        return NanoIdService::class;
    }
}