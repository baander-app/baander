<?php

declare(strict_types=1);

namespace App\Party\Infrastructure;

use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Application\Port\PlaybackSynchronizationPortInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class PlaybackSynchronizer implements PlaybackSynchronizationPortInterface
{
    private const MAX_JITTER = 2.0;
    private const EMA_ALPHA = 0.3;

    public function __construct(
        private readonly PartySessionPortInterface $sessionPort,
        private readonly PartyMemberPortInterface $memberPort,
    ) {
    }

    public function synchronize(Uuid $sessionId, Uuid $userId, float $clientPosition, float $clientLatency): float
    {
        $member = $this->memberPort->findByUserAndSession($userId, $sessionId);
        if ($member === null) {
            throw new AccessDeniedException('Party membership required.');
        }

        $serverPosition = $this->sessionPort->syncPlayback($sessionId, $clientPosition, $clientLatency);

        $drift = abs($serverPosition - $clientPosition);
        $jitter = min($drift, self::MAX_JITTER);

        // Exponential moving average for smooth jitter compensation
        $currentJitter = $member->getJitterCompensation();
        $smoothedJitter = ($currentJitter > 0)
            ? (self::EMA_ALPHA * $jitter) + ((1 - self::EMA_ALPHA) * $currentJitter)
            : $jitter;

        $member->updateSyncPosition($serverPosition, $smoothedJitter);
        $this->memberPort->save($member);

        return $serverPosition;
    }
}
