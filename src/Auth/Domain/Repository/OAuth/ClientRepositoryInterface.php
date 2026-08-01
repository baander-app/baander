<?php

declare(strict_types=1);

namespace App\Auth\Domain\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\Client;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

/**
 * Repository interface for OAuth clients.
 */
interface ClientRepositoryInterface
{
    public function saveClient(Client $client): void;

    public function findClientByUuid(Uuid $uuid): ?Client;

    public function findClientByPublicId(PublicId $publicId): ?Client;

    /**
     * @return Client[]
     */
    public function findPersonalAccessClients(): array;

    /**
     * Find personal access clients belonging to a specific user.
     *
     * @return Client[]
     */
    public function findPersonalAccessClientsByUser(Uuid $userId): array;
}
