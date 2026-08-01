<?php

declare(strict_types=1);

namespace App\Party\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class TransferHostCommand
{
    public function __construct(
        private Uuid $sessionId,
        private Uuid $currentHostUserId,
        private Uuid $newHostUserId,
    ) {
    }

    public function getSessionId(): Uuid { return $this->sessionId; }
    public function getCurrentHostUserId(): Uuid { return $this->currentHostUserId; }
    public function getNewHostUserId(): Uuid { return $this->newHostUserId; }
}
