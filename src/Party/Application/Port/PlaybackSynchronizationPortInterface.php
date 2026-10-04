<?php

declare(strict_types=1);

namespace App\Party\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface PlaybackSynchronizationPortInterface
{
    public function synchronize(Uuid $sessionId, Uuid $userId, float $clientPosition, float $clientLatency): float;
}
