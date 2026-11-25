<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\CreatePersonalAccessClientCommand;
use App\Auth\Application\Exception\PersonalAccessClientLimitReachedException;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Creates a personal access client for its owner, who may hold at most CLIENTS_PER_USER that are not revoked. */
final readonly class CreatePersonalAccessClientHandler
{
    public const int CLIENTS_PER_USER = 20;

    public function __construct(
        private ClientRepositoryInterface $clientRepository,
        private TransactionPortInterface $transaction,
    ) {
    }

    /** @throws PersonalAccessClientLimitReachedException when the owner already holds CLIENTS_PER_USER clients */
    #[AsMessageHandler]
    public function __invoke(CreatePersonalAccessClientCommand $command): Client
    {
        $client = Client::createPersonalAccess($command->name, $command->userId);

        // The owner's lock makes concurrent creates count in turn, so none passes the cap.
        $this->transaction->run(function () use ($client, $command): void {
            $this->clientRepository->lockPersonalAccessClientsOf($command->userId);
            if ($this->clientRepository->countPersonalAccessClientsOf($command->userId) >= self::CLIENTS_PER_USER) {
                throw new PersonalAccessClientLimitReachedException(self::CLIENTS_PER_USER);
            }

            $this->clientRepository->saveClient($client);
        });

        return $client;
    }
}
