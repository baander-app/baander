<?php

declare(strict_types=1);

namespace App\Party\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class StartPlaybackCommand
{
    public function __construct(
        private Uuid $sessionId,
        private readonly Uuid $userId,
        private ?float $position = null,
    ) {
    }

    public function getSessionId(): Uuid { return $this->sessionId; }
    public function getUserId(): Uuid { return $this->userId; }
    public function getPosition(): ?float { return $this->position; }
}
