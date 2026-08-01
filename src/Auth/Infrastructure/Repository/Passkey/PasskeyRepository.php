<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Repository\Passkey;

use App\Auth\Domain\Model\Passkey\Passkey;
use App\Auth\Domain\Model\Passkey\PasskeyState;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\PasskeyEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class PasskeyRepository implements PasskeyRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Passkey $passkey, Uuid $userId): void
    {
        $existing = $this->entityManager
            ->getRepository(PasskeyEntity::class)
            ->find($passkey->getId());

        if ($existing !== null) {
            $this->syncToEntity($passkey, $existing);
            $this->entityManager->flush();

            return;
        }

        $userEntity = $this->entityManager
            ->getRepository(UserEntity::class)
            ->find($userId);

        $entity = new PasskeyEntity(
            $userEntity,
            $passkey->getName(),
            $passkey->getCredentialId(),
            $passkey->getData(),
            $passkey->getCounter(),
        );

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function remove(Passkey $passkey): void
    {
        $entity = $this->entityManager
            ->getRepository(PasskeyEntity::class)
            ->find($passkey->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    public function ofCredentialId(string $credentialId): ?Passkey
    {
        $entity = $this->entityManager
            ->getRepository(PasskeyEntity::class)
            ->findOneBy(['credentialId' => $credentialId]);

        if ($entity === null) {
            return null;
        }

        return $this->toDomain($entity);
    }

    public function ofId(Uuid $id): ?Passkey
    {
        $entity = $this->entityManager
            ->getRepository(PasskeyEntity::class)
            ->find($id);

        if ($entity === null) {
            return null;
        }

        return $this->toDomain($entity);
    }

    /**
     * @return list<Passkey>
     */
    public function forUser(Uuid $userId): array
    {
        $entities = $this->entityManager
            ->getRepository(PasskeyEntity::class)
            ->findBy(['user' => $userId]);

        return array_map(
            fn (PasskeyEntity $e) => $this->toDomain($e),
            $entities,
        );
    }

    public function userIdForCredentialId(string $credentialId): ?Uuid
    {
        $entity = $this->entityManager
            ->getRepository(PasskeyEntity::class)
            ->findOneBy(['credentialId' => $credentialId]);

        if ($entity === null) {
            return null;
        }

        return $entity->getUser()->getId();
    }

    public function markUsed(Passkey $passkey): void
    {
        $entity = $this->entityManager
            ->getRepository(PasskeyEntity::class)
            ->find($passkey->getId());

        if ($entity !== null) {
            $entity->markUsed();
            $this->entityManager->flush();
        }
    }

    private function toDomain(PasskeyEntity $entity): Passkey
    {
        return Passkey::reconstitute(new PasskeyState(
            id: $entity->getId(),
            name: $entity->getName(),
            credentialId: $entity->getCredentialId(),
            data: $entity->getData(),
            counter: $entity->getCounter(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
            lastUsedAt: $entity->getLastUsedAt(),
        ));
    }

    private function syncToEntity(Passkey $passkey, PasskeyEntity $entity): void
    {
        $entity->setName($passkey->getName());
        $entity->setCounter($passkey->getCounter());

        if ($passkey->getLastUsedAt() !== null && $entity->getLastUsedAt() === null) {
            $entity->markUsed();
        }
    }
}
