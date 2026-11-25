<?php

declare(strict_types=1);

namespace App\Discovery\Application\CommandHandler;

use App\Discovery\Application\Command\CreatePairingCodeCommand;
use App\Discovery\Application\Port\DiscoveryPortInterface;
use App\Discovery\Application\Port\ServerInstancePortInterface;
use App\Discovery\Domain\Model\PairingSession;
use App\Shared\Application\Exception\NotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class CreatePairingCodeHandler
{
    public function __construct(
        private readonly ServerInstancePortInterface $serverPort,
        private readonly DiscoveryPortInterface $discoveryPort,
    ) {
    }

    /** @throws NotFoundException for an unknown server */
    #[AsMessageHandler]
    public function __invoke(CreatePairingCodeCommand $command): PairingSession
    {
        $server = $this->serverPort->findByPublicId($command->getServerPublicId());
        if ($server === null) {
            throw new NotFoundException('Server not found.');
        }

        return $this->discoveryPort->createPairingSession(
            serverId: $server->getId(),
            serverPublicId: $server->getPublicId(),
            serverUrl: $server->getServerUrl(),
            serverName: $server->getName(),
            method: $command->getMethod(),
        );
    }
}
