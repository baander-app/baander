<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\CreatePersonalAccessClientCommand;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class CreatePersonalAccessClientHandler
{
    public function __construct(
        private ClientRepositoryInterface $clientRepository,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(CreatePersonalAccessClientCommand $command): Client
    {
        $client = Client::createPersonalAccess($command->name, $command->userId);
        $this->clientRepository->saveClient($client);

        return $client;
    }
}
