<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\RevokeClientCommand;
use App\Auth\Application\Exception\ClientNotFoundException;
use App\Auth\Application\Service\ClientRevoker;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Revokes a user's personal access client and every token issued to it.
 */
final readonly class RevokeClientHandler
{
    public function __construct(
        private ClientRepositoryInterface $clientRepository,
        private ClientRevoker $clientRevoker,
    ) {
    }

    /** @throws ClientNotFoundException The client is unknown or owned by someone else. */
    #[AsMessageHandler]
    public function __invoke(RevokeClientCommand $command): void
    {
        $client = $this->clientRepository->findClientByPublicId($command->clientPublicId);
        if ($client === null || !$client->isOwnedBy($command->userId)) {
            throw ClientNotFoundException::forOwner();
        }

        $this->clientRevoker->revoke($client);
    }
}
