<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use App\Shared\Application\Exception\ConflictException;

/** The user already holds the most personal access clients allowed; revoking one makes room. */
final class PersonalAccessClientLimitReachedException extends ConflictException
{
    public function __construct(int $limit)
    {
        parent::__construct(
            sprintf('A user may hold at most %d personal access clients. Revoke one before creating another.', $limit),
            ['reason' => 'personal_access_client_limit', 'limit' => $limit],
        );
    }
}
