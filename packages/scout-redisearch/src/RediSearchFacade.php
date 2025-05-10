<?php

namespace Baander\ScoutRediSearch;

use Illuminate\Support\Facades\Facade;

class RediSearchFacade extends Facade
{
    public static function getFacadeAccessor(): string
    {
        return 'redisearch';
    }
}