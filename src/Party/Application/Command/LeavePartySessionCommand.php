<?php

declare(strict_types=1);

namespace App\Party\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class LeavePartySessionCommand
{
    public function __construct(
        private Uuid $userId,
        private Uuid $sessionId,
    ) {
    }

    public function getUserId(): Uuid { return $this->userId; }
    public function getSessionId(): Uuid { return $this->sessionId; }
}
