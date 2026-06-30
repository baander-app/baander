<?php

declare(strict_types=1);

namespace App\Radio\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class StopRadioCommand
{
    public function __construct(
        private Uuid $userId,
    ) {
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }
}
