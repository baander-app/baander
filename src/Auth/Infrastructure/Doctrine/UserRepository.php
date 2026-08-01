<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Doctrine;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\UserState;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class UserRepository implements UserRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(User $user): void
    {
        $entity = $this->findEntityOrCreate($user);
        $this->syncToEntity($user, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findByEmail(Email $email): ?User
    {
        $entity = $this->findEntityByEmail($email);

        if ($entity === null) {
            return null;
        }

        return $this->toDomain($entity);
    }

    public function findByPublicId(PublicId $publicId): ?User
    {
        $entity = $this->entityManager
            ->getRepository(UserEntity::class)
            ->findOneBy(['publicId' => $publicId]);

        if ($entity === null) {
            return null;
        }

        return $this->toDomain($entity);
    }

    public function findByUuid(Uuid $uuid): ?User
    {
        $entity = $this->entityManager
            ->getRepository(UserEntity::class)
            ->find($uuid);

        if ($entity === null) {
            return null;
        }

        return $this->toDomain($entity);
    }

    public function existsWithEmail(Email $email): bool
    {
        return $this->findEntityByEmail($email) !== null;
    }

    public function delete(Uuid $id): void
    {
        $entity = $this->entityManager
            ->getRepository(UserEntity::class)
            ->find($id);

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    public function findAll(?string $roleFilter = null, ?bool $disabledFilter = null, int $limit = 50, int $offset = 0): array
    {
        $sql = 'SELECT id FROM users WHERE 1=1';
        $params = [];

        if ($roleFilter !== null) {
            $sql .= ' AND roles @> :role';
            $params['role'] = json_encode([$roleFilter]);
        }

        if ($disabledFilter !== null) {
            $sql .= ' AND disabled = :disabled';
            $params['disabled'] = $disabledFilter;
        }

        $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';

        $conn = $this->entityManager->getConnection();
        $stmt = $conn->executeQuery(
            $sql,
            [...$params, 'limit' => $limit, 'offset' => $offset],
        );

        $ids = array_map(static fn(array $row) => Uuid::fromString($row['id']), $stmt->fetchAllAssociative());

        $users = [];
        foreach ($ids as $id) {
            $entity = $this->entityManager->find(UserEntity::class, $id);
            if ($entity !== null) {
                $users[] = $this->toDomain($entity);
            }
        }

        return $users;
    }

    public function count(?string $roleFilter = null, ?bool $disabledFilter = null): int
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE 1=1';
        $params = [];

        if ($roleFilter !== null) {
            $sql .= ' AND roles @> :role';
            $params['role'] = json_encode([$roleFilter]);
        }

        if ($disabledFilter !== null) {
            $sql .= ' AND disabled = :disabled';
            $params['disabled'] = $disabledFilter;
        }

        $conn = $this->entityManager->getConnection();

        return (int) $conn->executeQuery($sql, $params)->fetchOne();
    }

    private function toDomain(UserEntity $entity): User
    {
        return User::reconstitute(new UserState(
            id: $entity->getId(),
            publicId: $entity->getPublicId(),
            name: $entity->getName(),
            email: $entity->getEmail(),
            password: $entity->getPassword(),
            totpSecret: $entity->getTotpSecret(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
            emailVerifiedAt: $entity->getEmailVerifiedAt(),
            roles: $entity->getRoles(),
            disabled: $entity->isDisabled(),
        ));
    }

    private function syncToEntity(User $user, UserEntity $entity): void
    {
        $entity->setName($user->getName());
        $entity->setEmail($user->getEmail());
        $entity->setPassword($user->getPassword());
        $entity->setTotpSecret($user->getTotpSecret() ?? '');
        $entity->setRoles($user->getRoles());
        $entity->setDisabled($user->isDisabled());

        if ($user->isEmailVerified() && $entity->getEmailVerifiedAt() === null) {
            $entity->markEmailAsVerified();
        }
    }

    private function findEntityOrCreate(User $user): UserEntity
    {
        $existing = $this->entityManager
            ->getRepository(UserEntity::class)
            ->find($user->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new UserEntity(
            $user->getPublicId(),
            $user->getName(),
            $user->getEmail(),
            $user->getPassword(),
            $user->getTotpSecret() ?? '',
            $user->getId(),
            $user->getRoles(),
        );
    }

    private function findEntityByEmail(Email $email): ?UserEntity
    {
        $qb = $this->entityManager->createQueryBuilder();

        return $qb->select('u')
            ->from(UserEntity::class, 'u')
            ->where('LOWER(u.email) = LOWER(:email)')
            ->setParameter('email', $email->toString())
            ->getQuery()
            ->getOneOrNullResult();
    }
}
