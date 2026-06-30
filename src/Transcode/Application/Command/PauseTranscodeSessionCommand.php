<?php

declare(strict_types=1);

namespace App\Transcode\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class PauseTranscodeSessionCommand
{
    public function __construct(
        private Uuid $sessionId,
    ) {
    }

    public function getSessionId(): Uuid { return $this->sessionId; }
}
