<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

use App\Shared\Domain\Model\Uuid;

/** Create a personal access client owned by the requesting user. */
final readonly class CreatePersonalAccessClientCommand
{
    public function __construct(
        public Uuid $userId,
        public string $name,
    ) {
    }
}
