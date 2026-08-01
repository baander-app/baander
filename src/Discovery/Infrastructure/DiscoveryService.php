<?php

declare(strict_types=1);

namespace App\Discovery\Infrastructure;

use App\Discovery\Application\Port\DiscoveryPortInterface;
use App\Discovery\Domain\Model\PairingSession;
use App\Discovery\Domain\Repository\PairingSessionRepositoryInterface;
use App\Discovery\Domain\ValueObject\AuthenticationMethod;
use App\Discovery\Domain\ValueObject\PairingCode;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

final readonly class DiscoveryService implements DiscoveryPortInterface
{
    public function __construct(
        private PairingSessionRepositoryInterface $pairingSessionRepository,
    ) {
    }

    public function createPairingSession(
        Uuid $serverId,
        PublicId $serverPublicId,
        string $serverUrl,
        string $serverName,
        AuthenticationMethod $method,
    ): PairingSession {
        $session = PairingSession::create(
            serverId: $serverId,
            serverPublicId: $serverPublicId,
            serverUrl: $serverUrl,
            serverName: $serverName,
            method: $method,
        );
        $this->pairingSessionRepository->save($session);

        return $session;
    }

    public function completePairing(PairingSession $session): void
    {
        $session->complete();
        $this->pairingSessionRepository->save($session);
    }

    public function findByPairingCode(string $pairingCode): ?PairingSession
    {
        return $this->pairingSessionRepository->findByPairingCode(
            PairingCode::fromString($pairingCode),
        );
    }
}
