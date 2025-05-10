<?php

namespace Baander\ScoutRediSearch;

use Baander\RedisStack\RedisStack;

class RediSearch
{
    private RedisStack $redisStack;

    public function __construct(
        RedisStack $redisStack,
    )
    {
        $this->redisStack = $redisStack;
    }

    public function getClient(): RedisStack
    {
        return $this->redisStack;
    }
}