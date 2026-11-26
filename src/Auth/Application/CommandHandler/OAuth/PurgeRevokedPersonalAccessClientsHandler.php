<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\PurgeRevokedPersonalAccessClientsCommand;
use App\Auth\Application\DTO\PurgedPersonalAccessClientsDTO;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes revoked personal access clients.
 *
 * Revocation already revoked their tokens, which cascade with the client row. A token
 * presented after the purge finds no row, which the resource server and the refresh grant
 * read as revoked.
 */
final readonly class PurgeRevokedPersonalAccessClientsHandler
{
    public function __construct(
        private ClientRepositoryInterface $clientRepository,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(PurgeRevokedPersonalAccessClientsCommand $command): PurgedPersonalAccessClientsDTO
    {
        return new PurgedPersonalAccessClientsDTO(
            clients: $this->clientRepository->deleteRevokedPersonalAccessClients(),
        );
    }
}
