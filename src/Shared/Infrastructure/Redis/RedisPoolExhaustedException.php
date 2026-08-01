<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Redis;

final class RedisPoolExhaustedException extends \RuntimeException
{
    public function __construct(int $maxSize, int $active)
    {
        parent::__construct(sprintf(
            'Redis connection pool exhausted (max: %d, active: %d)',
            $maxSize,
            $active,
        ));
    }
}
