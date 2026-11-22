<?php

declare(strict_types=1);

namespace App\Discovery\Infrastructure;

use App\Discovery\Application\Port\DiscoveryPortInterface;
use App\Discovery\Domain\Model\PairingSession;
use App\Discovery\Domain\Repository\PairingSessionRepositoryInterface;
use App\Discovery\Domain\ValueObject\AuthenticationMethod;
use App\Discovery\Domain\ValueObject\PairingCode;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class DiscoveryService implements DiscoveryPortInterface
{
    public function __construct(
        private PairingSessionRepositoryInterface $pairingSessionRepository,
        private TransactionPortInterface $transaction,
    ) {
    }

    /** The per-IP discovery limit bounds this purge, so pairing_sessions needs no scheduled cleanup. */
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

        return $this->transaction->run(function () use ($session): PairingSession {
            $this->pairingSessionRepository->deleteExpired(new DateTimeImmutable());
            $this->pairingSessionRepository->persist($session);

            return $session;
        });
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
