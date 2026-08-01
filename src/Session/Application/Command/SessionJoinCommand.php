<?php

declare(strict_types=1);

namespace App\Session\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class SessionJoinCommand
{
    public function __construct(
        private Uuid $userId,
        private Uuid $deviceId,
    ) {
    }

    public function getUserId(): Uuid { return $this->userId; }
    public function getDeviceId(): Uuid { return $this->deviceId; }
}
