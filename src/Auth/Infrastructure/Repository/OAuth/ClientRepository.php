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
            $client->getSecret(),
            null,
            $client->isPersonalAccessClient(),
            $client->isPasswordClient(),
            $client->isDeviceClient(),
            $client->isConfidential(),
            $client->isFirstParty(),
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

    // --- Internal ---

    private function toDomain(ClientEntity $entity): Client
    {
        return Client::reconstitute(new ClientState(
            id: $entity->getId(),
            publicId: $entity->getPublicId(),
            name: $entity->getName(),
            secret: $entity->getSecret(),
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

        if ($client->getSecret() !== null) {
            $entity->setSecret($client->getSecret());
        }

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
