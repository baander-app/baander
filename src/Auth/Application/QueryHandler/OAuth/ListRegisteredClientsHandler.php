<?php

declare(strict_types=1);

namespace App\Auth\Application\QueryHandler\OAuth;

use App\Auth\Application\Query\OAuth\ListRegisteredClientsQuery;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Lists the clients administrators see, for the admin API and app:oauth:client:list.
 */
final readonly class ListRegisteredClientsHandler
{
    public function __construct(
        private ClientRepositoryInterface $clientRepository,
    ) {
    }

    /** @return Client[] */
    #[AsMessageHandler]
    public function __invoke(ListRegisteredClientsQuery $query): array
    {
        return $this->clientRepository->findAllExceptPersonalAccess();
    }
}
