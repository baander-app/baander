<?php

declare(strict_types=1);

namespace App\Auth\Application\Query\OAuth;

use App\Shared\Domain\Model\Uuid;

/** List the active personal access clients owned by a user. */
final readonly class ListPersonalAccessClientsQuery
{
    public function __construct(
        public Uuid $userId,
    ) {
    }
}
