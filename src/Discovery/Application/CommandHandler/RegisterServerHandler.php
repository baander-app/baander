<?php

declare(strict_types=1);

namespace App\Discovery\Application\CommandHandler;

use App\Discovery\Application\Command\RegisterServerCommand;
use App\Discovery\Application\Port\ServerInstancePortInterface;
use App\Discovery\Domain\Event\ServerRegistered;
use App\Discovery\Domain\Model\ServerInstance;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class RegisterServerHandler
{
    public function __construct(
        private readonly ServerInstancePortInterface $serverPort,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(RegisterServerCommand $command): ServerInstance
    {
        $server = $this->serverPort->register(
            serverUrl: $command->getServerUrl(),
            name: $command->getName(),
            version: $command->getVersion(),
            apiKey: $command->getApiKey(),
        );

        $this->eventDispatcher->dispatch(new ServerRegistered(
            serverId: $server->getId(),
            serverPublicId: $server->getPublicId(),
            serverUrl: $server->getServerUrl(),
            name: $server->getName(),
        ));

        return $server;
    }
}
