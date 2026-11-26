<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

final readonly class PurgedPersonalAccessClientsDTO
{
    public function __construct(
        public int $clients,
    ) {
    }
}
