<?php

declare(strict_types=1);

namespace App\Auth\Application\QueryHandler\OAuth;

use App\Auth\Application\Query\OAuth\ListPersonalAccessClientsQuery;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class ListPersonalAccessClientsHandler
{
    public function __construct(
        private ClientRepositoryInterface $clientRepository,
    ) {
    }

    /** @return Client[] Active clients, newest first. */
    #[AsMessageHandler]
    public function __invoke(ListPersonalAccessClientsQuery $query): array
    {
        return $this->clientRepository->findPersonalAccessClientsByUser($query->userId);
    }
}
