<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Doctrine;

use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Auth\Application\Port\PasswordResetTokenRepositoryInterface;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\UserState;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMappingBuilder;

final class UserRepository implements UserRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PasswordResetTokenRepositoryInterface $passwordResetTokens,
        private readonly EmailVerificationTokenRepositoryInterface $emailVerificationTokens,
    ) {
    }

    public function save(User $user): void
    {
        $entity = $this->findEntityOrCreate($user);

        // A reset token was issued for the credentials the account had then. Any change of
        // address or password hash ends it, as Django's reset tokens do; that includes a
        // rehash on login, which is harmless because the user has just proven the password.
        // Revoking before the flush means a failed save can only cost an outstanding token.
        if ($this->entityManager->contains($entity)
            && ($entity->getEmail() !== $user->getEmail() || $entity->getPassword() !== $user->getPassword())) {
            $this->passwordResetTokens->revokeForUser($user->getId());
        }

        // A verification token vouches for the address it was sent to; a new address needs a
        // new token, which the email change issues once the user is saved.
        if ($this->entityManager->contains($entity) && $entity->getEmail() !== $user->getEmail()) {
            $this->emailVerificationTokens->revokeForUser($user->getId());
        }

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
        // One query hydrates the page. The role filter needs jsonb containment, which DQL cannot
        // express, so this is native SQL mapped onto the entity. Users already in the identity
        // map come back as those managed instances, as find() returns them.
        $rsm = new ResultSetMappingBuilder($this->entityManager);
        $rsm->addRootEntityFromClassMetadata(UserEntity::class, 'u');

        $sql = sprintf('SELECT %s FROM users u WHERE 1=1', $rsm->generateSelectClause());
        $params = [];
        $types = ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER];

        if ($roleFilter !== null) {
            $sql .= ' AND u.roles @> :role';
            $params['role'] = json_encode([$roleFilter]);
        }

        if ($disabledFilter !== null) {
            $sql .= ' AND u.disabled = :disabled';
            $params['disabled'] = $disabledFilter;
            $types['disabled'] = ParameterType::BOOLEAN;
        }

        // created_at keeps whole seconds, so users created together tie; the id makes the order
        // total, or a page boundary between them could repeat one user and skip another.
        $sql .= ' ORDER BY u.created_at DESC, u.id DESC LIMIT :limit OFFSET :offset';

        $query = $this->entityManager->createNativeQuery($sql, $rsm);
        foreach ([...$params, 'limit' => $limit, 'offset' => $offset] as $name => $value) {
            $query->setParameter($name, $value, $types[$name] ?? null);
        }

        /** @var list<UserEntity> $entities */
        $entities = $query->getResult();

        return array_map($this->toDomain(...), $entities);
    }

    public function count(?string $roleFilter = null, ?bool $disabledFilter = null): int
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE 1=1';
        $params = [];
        $types = [];

        if ($roleFilter !== null) {
            $sql .= ' AND roles @> :role';
            $params['role'] = json_encode([$roleFilter]);
        }

        if ($disabledFilter !== null) {
            $sql .= ' AND disabled = :disabled';
            $params['disabled'] = $disabledFilter;
            $types['disabled'] = ParameterType::BOOLEAN;
        }

        $conn = $this->entityManager->getConnection();

        return (int) $conn->executeQuery($sql, $params, $types)->fetchOne();
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
        // The domain clears verification when the address changes, so copy it both ways.
        $entity->setEmailVerifiedAt($user->getEmailVerifiedAt());
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

        // users.email is CITEXT, so plain equality is case-insensitive and uses uniq_users_email;
        // LOWER() on the column would turn it into a text comparison that only a sequential scan
        // can answer. Keep the parameter an untyped string: PostgreSQL then infers citext from the
        // column. A parameter typed or cast as text makes the comparison text = text, which is
        // case-sensitive and skips the index as well.
        return $qb->select('u')
            ->from(UserEntity::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', $email->toString())
            ->getQuery()
            ->getOneOrNullResult();
    }
}
