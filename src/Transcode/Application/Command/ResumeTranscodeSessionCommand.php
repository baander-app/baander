<?php

declare(strict_types=1);

namespace App\Transcode\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class ResumeTranscodeSessionCommand
{
    public string $requestId;

    public function __construct(
        private Uuid $sessionId,
        ?string $requestId = null,
    ) {
        $this->requestId = $requestId ?? Uuid::generate()->toString();
    }

    public function getSessionId(): Uuid { return $this->sessionId; }
}
