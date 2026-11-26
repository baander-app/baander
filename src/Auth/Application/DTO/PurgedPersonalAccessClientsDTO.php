<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

/** How many revoked personal access clients a purge deleted. */
final readonly class PurgedPersonalAccessClientsDTO
{
    public function __construct(
        public int $clients,
    ) {
    }
}
