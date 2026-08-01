<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\User;

final readonly class EnableUserCommand
{
    public function __construct(
        private string $identifier,
    ) {
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }
}
