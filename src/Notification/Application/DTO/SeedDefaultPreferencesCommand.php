<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

use App\Shared\Domain\Model\Uuid;

final readonly class SeedDefaultPreferencesCommand
{
    public function __construct(
        public Uuid $userId,
    ) {
    }
}
