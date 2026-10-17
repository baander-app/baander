<?php

declare(strict_types=1);

namespace App\Transcode\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class UpdateTranscodePositionCommand
{
    public string $requestId;

    public function __construct(
        public Uuid $sessionId,
        public float $position,
        public string $action,
        ?string $requestId = null,
    ) {
        $this->requestId = $requestId ?? Uuid::generate()->toString();
    }
}
