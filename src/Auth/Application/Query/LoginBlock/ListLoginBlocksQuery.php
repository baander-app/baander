<?php

declare(strict_types=1);

namespace App\Auth\Application\Query\LoginBlock;

/**
 * One page of login blocks, newest first: the admin panel's GET /api/admin/login-blocks and `app:login-block:list`.
 */
final readonly class ListLoginBlocksQuery
{
    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 100;

    public function __construct(
        /** 1 to MAX_LIMIT. */
        public int $limit = self::DEFAULT_LIMIT,
        public int $offset = 0,
    ) {
    }
}
