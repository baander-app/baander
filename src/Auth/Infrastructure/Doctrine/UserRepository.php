<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Doctrine;

use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Auth\Application\Port\PasswordResetTokenRepositoryInterface;
use App\Auth\Domain\Model\User;
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
    /**
     * The advisory lock that serializes the changes which can take an enabled super admin away:
     * hashtextextended(key, seed). Its own seed keeps it apart from the other advisory locks of
     * the application; a hash collision only makes unrelated work wait for these changes.
     */
    private const string SUPER_ADMIN_REMOVAL_LOCK_KEY = 'super-admin-removals';
    private const int SUPER_ADMIN_REMOVAL_LOCK_SEED = 20261122141800;

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
        // address or password saved through the aggregate ends it. Login can upgrade the hash
        // separately while keeping the same credentials.
        // Revoking before the flush means a failed save can only cost an outstanding token.
        if ($this->entityManager->contains($entity)
            && ($entity->getEmail() !== $user->getEmail() || $entity->getPassword() !== $user->getPassword())) {
            $this->passwordResetTokens->revokeForUser($user->getId());
        }

        // A verification token vouches for the address it was sent to: the current one, or the
        // pending one a self-service change asked for. A change of either needs a new token,
        // which the change issues once the user is saved.
        if ($this->entityManager->contains($entity)
            && ($entity->getEmail() !== $user->getEmail() || $entity->getPendingEmail() !== $user->getPendingEmail())) {
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

        return UserEntityMapping::toDomain($entity);
    }

    public function findByPublicId(PublicId $publicId): ?User
    {
        $entity = $this->entityManager
            ->getRepository(UserEntity::class)
            ->findOneBy(['publicId' => $publicId]);

        if ($entity === null) {
            return null;
        }

        return UserEntityMapping::toDomain($entity);
    }

    public function findByUuid(Uuid $uuid): ?User
    {
        $entity = $this->entityManager
            ->getRepository(UserEntity::class)
            ->find($uuid);

        if ($entity === null) {
            return null;
        }

        return UserEntityMapping::toDomain($entity);
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

        return array_map(UserEntityMapping::toDomain(...), $entities);
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

    public function lockSuperAdminRemovals(): void
    {
        $connection = $this->entityManager->getConnection();
        if (!$connection->isTransactionActive()) {
            // Outside a transaction the lock would end with its own statement and serialize nothing.
            throw new \LogicException('Lock super admin removals inside the transaction that makes the removal.');
        }

        // SQL because DQL has no advisory lock. An advisory lock rather than row locks on the
        // super admins: lockCredentialEpoch takes FOR NO KEY UPDATE on a user's row at every
        // sign-in, so locking those rows here would queue the super admins' sign-ins too.
        $connection->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(:key, ' . self::SUPER_ADMIN_REMOVAL_LOCK_SEED . '))',
            ['key' => self::SUPER_ADMIN_REMOVAL_LOCK_KEY],
        )->free();
    }

    public function soleEnabledSuperAdmin(): ?Uuid
    {
        // SQL for the jsonb containment DQL cannot express, as in count(). A statement of its own
        // after the lock: under READ COMMITTED it sees what the lock's previous holder committed.
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT id FROM users WHERE roles @> :role AND NOT disabled LIMIT 2',
            ['role' => json_encode(['ROLE_SUPER_ADMIN'])],
        );

        return count($ids) === 1 ? Uuid::fromString((string) $ids[0]) : null;
    }

    public function upgradePasswordHash(
        Uuid $id,
        #[\SensitiveParameter] string $verifiedHash,
        #[\SensitiveParameter] string $replacementHash,
    ): void {
        // Update only the verified hash. A concurrent login may have upgraded it already.
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE users SET password = :replacementHash WHERE id = :id AND password = :verifiedHash',
            ['id' => $id->toString(), 'verifiedHash' => $verifiedHash, 'replacementHash' => $replacementHash],
        );

        // Login loaded the user before taking the lock. Keep the managed entity current.
        $entity = $this->entityManager->find(UserEntity::class, $id);
        if ($entity !== null) {
            $this->entityManager->refresh($entity);
        }
    }

    public function spendTotpStep(Uuid $id, int $step): bool
    {
        // One statement: under concurrent spends of one step, the row lock serializes them and
        // the later one re-evaluates the condition against the committed step and matches no row.
        $spent = $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE users
                   SET totp_last_used_step = :step
                 WHERE id = :id
                   AND (totp_last_used_step IS NULL OR totp_last_used_step < :step)
                SQL,
            ['step' => $step, 'id' => $id->toString()],
            ['step' => ParameterType::INTEGER],
        );

        return $spent === 1;
    }

    public function clearTotpStep(Uuid $id): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE users SET totp_last_used_step = NULL WHERE id = :id',
            ['id' => $id->toString()],
        );
    }

    public function lockCredentialEpoch(Uuid $id): ?int
    {
        $connection = $this->entityManager->getConnection();
        if (!$connection->isTransactionActive()) {
            // Outside a transaction the lock would end with its own statement and fence nothing.
            throw new \LogicException('The credential epoch is locked inside the issuing transaction.');
        }

        // FOR NO KEY UPDATE, not FOR SHARE: password login spends its TOTP step with an UPDATE of
        // this row in the same transaction, and two logins each holding a share lock would
        // deadlock upgrading it. FOR UPDATE would also block every insert whose foreign key
        // references the user; this mode leaves those inserts (FOR KEY SHARE) alone. A row lock
        // that waited for a committed update returns the updated row, so the epoch read here is
        // the one the waited-for password change wrote.
        $epoch = $connection->fetchOne(
            'SELECT credential_epoch FROM users WHERE id = :id FOR NO KEY UPDATE',
            ['id' => $id->toString()],
        );

        return $epoch === false ? null : (int) $epoch;
    }

    public function advanceCredentialEpoch(Uuid $id): int
    {
        $epoch = $this->entityManager->getConnection()->fetchOne(
            'UPDATE users SET credential_epoch = credential_epoch + 1 WHERE id = :id RETURNING credential_epoch',
            ['id' => $id->toString()],
        );
        if ($epoch === false) {
            throw new \LogicException(sprintf('User %s vanished while its password changed.', $id->toString()));
        }

        return (int) $epoch;
    }

    private function syncToEntity(User $user, UserEntity $entity): void
    {
        $entity->setName($user->getName());
        $entity->setEmail($user->getEmail());
        $entity->setPendingEmail($user->getPendingEmail());
        $entity->setPassword($user->getPassword());
        $entity->setTotpSecretCiphertext($user->getTotpSecretCiphertext());
        $entity->setRoles($user->getRoles());
        $entity->setDisabled($user->isDisabled());
        // The domain clears verification when the address changes, so copy it both ways.
        $entity->setEmailVerifiedAt($user->getEmailVerifiedAt());
        $entity->setTimestamps($user->getCreatedAt(), $user->getUpdatedAt());
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
