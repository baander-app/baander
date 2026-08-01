<?php

declare(strict_types=1);

namespace App\Party\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class SyncPlaybackCommand
{
    public function __construct(
        private Uuid $sessionId,
        private Uuid $userId,
        private float $clientPosition,
        private float $clientLatency,
    ) {
    }

    public function getSessionId(): Uuid { return $this->sessionId; }
    public function getUserId(): Uuid { return $this->userId; }
    public function getClientPosition(): float { return $this->clientPosition; }
    public function getClientLatency(): float { return $this->clientLatency; }
}
