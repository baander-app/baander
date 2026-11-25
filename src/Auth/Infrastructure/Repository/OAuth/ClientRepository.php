<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ClientState;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Pure domain repository for OAuth clients.
 */
final class ClientRepository implements ClientRepositoryInterface
{
    /**
     * Seed of hashtextextended(<user id>, seed) for the lock that serializes a user's personal
     * access client creations. Its own seed keeps it apart from the application's other advisory
     * locks; a hash collision only makes unrelated work wait.
     */
    private const int OWNER_CLIENTS_LOCK_SEED = 20261125175400;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JsonEncoder $jsonEncoder,
    ) {
    }

    public function saveClient(Client $client): void
    {
        $existing = $this->entityManager
            ->getRepository(ClientEntity::class)
            ->find($client->getId());

        if ($existing !== null) {
            $this->syncToEntity($client, $existing);
            $this->entityManager->flush();

            return;
        }

        $entity = new ClientEntity(
            $client->getPublicId(),
            $client->getName(),
            $this->jsonEncoder->encode($client->getRedirectUris(), 'json'),
            $client->getSecretHash(),
            null,
            $client->isPersonalAccessClient(),
            $client->isPasswordClient(),
            $client->isDeviceClient(),
            $client->isConfidential(),
            $client->isFirstParty(),
            id: $client->getId(),
            tokenEndpointAuthMethod: $client->getAuthMethod(),
            keySource: $client->getKeySource(),
        );
        $entity->setUserId($client->getUserId());

        if ($client->isRevoked()) {
            $entity->revoke();
        }

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findClientByUuid(Uuid $uuid): ?Client
    {
        $entity = $this->entityManager
            ->getRepository(ClientEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findClientByPublicId(PublicId $publicId): ?Client
    {
        $entity = $this->entityManager
            ->getRepository(ClientEntity::class)
            ->findOneBy(['publicId' => $publicId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function isFirstPartyClient(PublicId $publicId): bool
    {
        // chk_oauth_clients_personal_access_not_first_party keeps the flag off personal access clients.
        return $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM oauth_clients WHERE public_id = :public_id AND first_party',
            ['public_id' => $publicId->toString()],
        ) !== false;
    }

    public function findPersonalAccessClients(): array
    {
        $entities = $this->entityManager
            ->getRepository(ClientEntity::class)
            ->findBy(
                criteria: ['personalAccessClient' => true, 'revoked' => false],
                orderBy: ['createdAt' => 'DESC'],
            );

        return array_map(fn (ClientEntity $entity): Client => $this->toDomain($entity), $entities);
    }

    public function findPersonalAccessClientsByUser(Uuid $userId): array
    {
        $entities = $this->entityManager
            ->getRepository(ClientEntity::class)
            ->findBy(
                criteria: ['personalAccessClient' => true, 'revoked' => false, 'userId' => $userId],
                orderBy: ['createdAt' => 'DESC'],
            );

        return array_map(fn (ClientEntity $entity): Client => $this->toDomain($entity), $entities);
    }

    public function lockPersonalAccessClientsOf(Uuid $userId): void
    {
        $connection = $this->entityManager->getConnection();
        if (!$connection->isTransactionActive()) {
            // Outside a transaction the lock would end with its own statement and serialize nothing.
            throw new \LogicException('Lock a user\'s personal access clients inside the transaction that creates one.');
        }

        // SQL because DQL has no advisory lock. Not a row lock on the user: sign-in takes
        // FOR NO KEY UPDATE on that row, and a create must not queue behind it.
        $connection->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(:user, ' . self::OWNER_CLIENTS_LOCK_SEED . '))',
            ['user' => $userId->toString()],
        )->free();
    }

    public function countPersonalAccessClientsOf(Uuid $userId): int
    {
        // A statement of its own after the lock: under READ COMMITTED it sees what the lock's
        // previous holder committed.
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT count(*) FROM oauth_clients WHERE user_id = :user AND personal_access_client AND NOT revoked',
            ['user' => $userId->toString()],
        );
    }

    public function findAllExceptPersonalAccess(): array
    {
        $entities = $this->entityManager
            ->getRepository(ClientEntity::class)
            ->findBy(
                criteria: ['personalAccessClient' => false],
                orderBy: ['createdAt' => 'DESC', 'id' => 'ASC'],
            );

        return array_map(fn (ClientEntity $entity): Client => $this->toDomain($entity), $entities);
    }

    public function lockActiveClientForIssuance(Uuid $clientId): bool
    {
        $connection = $this->entityManager->getConnection();
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('The client lock must be taken inside the transaction that issues the tokens.');
        }

        // Raw SQL on purpose: the identity map may hold a client loaded before the
        // revocation committed. FOR SHARE waits for a concurrent revocation; under
        // READ COMMITTED PostgreSQL then re-evaluates the WHERE clause against the
        // committed row, so a client revoked meanwhile yields no row.
        return $connection->fetchOne(
            'SELECT 1 FROM oauth_clients WHERE id = :id AND revoked = FALSE FOR SHARE',
            ['id' => $clientId->toString()],
        ) !== false;
    }

    // --- Internal ---

    private function toDomain(ClientEntity $entity): Client
    {
        return Client::reconstitute(new ClientState(
            id: $entity->getId(),
            publicId: $entity->getPublicId(),
            name: $entity->getName(),
            secretHash: $entity->getSecretHash(),
            authMethod: $entity->getTokenEndpointAuthMethod(),
            keySource: $entity->getKeySource(),
            redirectUris: $this->parseRedirectUris($entity->getRedirect()),
            personalAccessClient: $entity->isPersonalAccessClient(),
            passwordClient: $entity->isPasswordClient(),
            deviceClient: $entity->isDeviceClient(),
            confidential: $entity->isConfidential(),
            firstParty: $entity->isFirstParty(),
            userId: $entity->getUserId(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
            revoked: $entity->isRevoked(),
        ));
    }

    private function syncToEntity(Client $client, ClientEntity $entity): void
    {
        $entity->setName($client->getName());
        $entity->setRedirect($this->jsonEncoder->encode($client->getRedirectUris(), 'json'));

        $entity->setSecretHash($client->getSecretHash());
        $entity->setKeySource($client->getKeySource());

        if ($client->isRevoked()) {
            $entity->revoke();
        }
    }

    /**
     * @return string[]
     */
    private function parseRedirectUris(string $redirect): array
    {
        $uris = $this->jsonEncoder->decode($redirect, 'json');

        if (is_array($uris)) {
            return $uris;
        }

        return array_filter(array_map('trim', explode(',', $redirect)));
    }
}
