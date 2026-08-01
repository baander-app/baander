<?php

declare(strict_types=1);

namespace App\Discovery\Application\CommandHandler;

use App\Discovery\Application\Command\CompletePairingCommand;
use App\Discovery\Application\Port\DiscoveryPortInterface;
use App\Discovery\Domain\Event\PairingCompleted;
use App\Discovery\Domain\Model\PairingSession;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class CompletePairingHandler
{
    public function __construct(
        private readonly DiscoveryPortInterface $discoveryPort,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(CompletePairingCommand $command): PairingSession
    {
        $session = $this->discoveryPort->findByPairingCode($command->getPairingCode());
        if ($session === null) {
            throw new RuntimeException('Pairing session not found.');
        }

        if ($session->isCompleted()) {
            return $session;
        }

        if ($session->isExpired()) {
            throw new RuntimeException('Pairing session has expired.');
        }

        if (!$session->getServerPublicId()->equals($command->getServerPublicId())) {
            throw new RuntimeException('Pairing session does not belong to this server.');
        }

        $this->discoveryPort->completePairing($session);

        $this->eventDispatcher->dispatch(new PairingCompleted(
            pairingId: $session->getId(),
            pairingPublicId: $session->getPublicId(),
            serverId: $session->getServerId(),
            method: $session->getMethod(),
        ));

        return $session;
    }
}
