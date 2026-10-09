<?php

declare(strict_types=1);

namespace App\Discovery\Application\CommandHandler;

use App\Discovery\Application\Command\RegisterServerCommand;
use App\Discovery\Application\Port\ServerInstancePortInterface;
use App\Discovery\Domain\Event\ServerRegistered;
use App\Discovery\Domain\Model\ServerInstance;
use App\Shared\Application\Exception\InvalidInputException;
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
        // Not FILTER_VALIDATE_URL: it refuses host names with an underscore, such as Docker service names.
        $url = parse_url($command->getServerUrl());
        if (!is_array($url) || !in_array($url['scheme'] ?? null, ['http', 'https'], true) || ($url['host'] ?? '') === '') {
            throw new InvalidInputException('The server URL must be a valid http or https URL.');
        }
        if (trim($command->getName()) === '') {
            throw new InvalidInputException('The server name must not be blank.');
        }
        if (trim($command->getVersion()) === '') {
            throw new InvalidInputException('The server version must not be blank.');
        }

        $server = $this->serverPort->register(
            serverUrl: $command->getServerUrl(),
            name: $command->getName(),
            version: $command->getVersion(),
            apiKey: bin2hex(random_bytes(32)),
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
