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

    /**
     * Every client except personal access clients, revoked ones included, newest first.
     *
     * @return Client[]
     */
    public function findAllExceptPersonalAccess(): array;

    /**
     * Locks the client's row against revocation until the current transaction ends.
     *
     * Call it inside the transaction that stores newly issued tokens, before they
     * are written. It takes a FOR SHARE row lock, which conflicts with the row
     * update that revokes a client. A revocation that committed first is seen
     * here, so issuance fails. A revocation that starts later waits for the
     * issuing transaction to commit, so it also revokes the new tokens.
     *
     * @return bool False when the client no longer exists or has been revoked
     *
     * @throws \LogicException When no transaction is active, because the lock would end at once
     */
    public function lockActiveClientForIssuance(Uuid $clientId): bool;
}
