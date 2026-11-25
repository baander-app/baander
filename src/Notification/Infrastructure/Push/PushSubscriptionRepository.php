<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Push;

use App\Notification\Application\DTO\PushSubscriptionRegistration;
use App\Notification\Application\DTO\PushSubscriptionRegistrationResult;
use App\Notification\Application\Port\PushSubscriptionRegistrationPortInterface;
use App\Notification\Application\Port\PushSubscriptionRemovalPortInterface;
use App\Notification\Infrastructure\Doctrine\Entity\PushSubscriptionEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

final class PushSubscriptionRepository implements PushSubscriptionRepositoryInterface, PushSubscriptionRegistrationPortInterface, PushSubscriptionRemovalPortInterface
{
    /**
     * Seed of hashtextextended(<owner id>, seed) for the lock that serializes an owner's
     * registrations. Its own seed keeps it apart from the application's other advisory locks;
     * a hash collision only makes unrelated work wait.
     */
    private const int OWNER_REGISTRATIONS_LOCK_SEED = 20261125142000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function registerForUser(Uuid $ownerId, PushSubscriptionRegistration $subscription): PushSubscriptionRegistrationResult
    {
        // DBAL's transaction, a savepoint inside a caller's: no entity is flushed here.
        [$result, $written] = $this->entityManager->getConnection()->transactional(
            fn (Connection $connection): array => $this->register($connection, $ownerId, $subscription),
        );
        foreach ($written as $id) {
            $this->detachIdentity($id);
        }

        return $result;
    }

    /**
     * Upserts under the owner's lock, so concurrent registrations count in turn and a created
     * row evicts the owner's longest-registered rows beyond the cap.
     *
     * @return array{PushSubscriptionRegistrationResult, list<string>} the result and the ids written or deleted
     */
    private function register(Connection $connection, Uuid $ownerId, PushSubscriptionRegistration $subscription): array
    {
        // SQL because DQL has no advisory lock.
        $connection->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(:owner, ' . self::OWNER_REGISTRATIONS_LOCK_SEED . '))',
            ['owner' => $ownerId->toString()],
        )->free();
        $row = $connection->executeQuery(
            <<<'SQL'
                INSERT INTO push_subscriptions AS subscription
                    (id, user_id, endpoint, public_key, auth_key, content_encoding, user_agent, created_at)
                VALUES (:id, :owner, :endpoint, :public_key, :auth_key, :encoding, :agent, :created_at)
                ON CONFLICT (endpoint) DO UPDATE
                SET public_key = EXCLUDED.public_key, auth_key = EXCLUDED.auth_key,
                    content_encoding = EXCLUDED.content_encoding, user_agent = EXCLUDED.user_agent
                WHERE subscription.user_id = EXCLUDED.user_id
                RETURNING subscription.id, OLD.id AS previous_id
                SQL,
            [
                'id' => new Uuid(), 'owner' => $ownerId, 'endpoint' => $subscription->endpoint,
                'public_key' => $subscription->publicKey, 'auth_key' => $subscription->authKey,
                'encoding' => $subscription->contentEncoding, 'agent' => $subscription->userAgent,
                'created_at' => new \DateTimeImmutable(),
            ],
            ['id' => 'uuid', 'owner' => 'uuid', 'created_at' => 'datetime_immutable'],
        )->fetchAssociative();
        if ($row === false) {
            return [PushSubscriptionRegistrationResult::Conflict, []];
        }
        if ($row['previous_id'] !== null) {
            return [PushSubscriptionRegistrationResult::Updated, [(string) $row['id']]];
        }

        // A statement of its own after the upsert: it sees the new row and, under READ
        // COMMITTED, every row the lock's previous holder committed.
        /** @var list<string> $evicted */
        $evicted = $connection->executeQuery(
            <<<'SQL'
                DELETE FROM push_subscriptions WHERE id IN (
                    SELECT id FROM push_subscriptions WHERE user_id = :owner
                    ORDER BY created_at DESC, id DESC
                    OFFSET :keep
                )
                RETURNING id
                SQL,
            ['owner' => $ownerId, 'keep' => self::SUBSCRIPTIONS_PER_USER],
            ['owner' => 'uuid', 'keep' => ParameterType::INTEGER],
        )->fetchFirstColumn();

        return [PushSubscriptionRegistrationResult::Created, [(string) $row['id'], ...array_map(strval(...), $evicted)]];
    }

    public function remove(PushSubscriptionEntity $subscription): void
    {
        $this->entityManager->remove($subscription);
        $this->entityManager->flush();
    }

    public function removeForUser(Uuid $ownerId, string $endpoint): void
    {
        $deletedIds = $this->entityManager->getConnection()->executeQuery(
            'DELETE FROM push_subscriptions WHERE user_id = :owner AND endpoint = :endpoint RETURNING id',
            ['owner' => $ownerId, 'endpoint' => $endpoint],
            ['owner' => 'uuid'],
        )->fetchFirstColumn();

        foreach ($deletedIds as $id) {
            $this->detachIdentity((string) $id);
        }
    }

    public function removeAllForUser(Uuid $ownerId): void
    {
        $deletedIds = $this->entityManager->getConnection()->executeQuery(
            'DELETE FROM push_subscriptions WHERE user_id = :owner RETURNING id',
            ['owner' => $ownerId],
            ['owner' => 'uuid'],
        )->fetchFirstColumn();
        foreach ($deletedIds as $id) {
            $this->detachIdentity((string) $id);
        }
    }

    public function find(Uuid $id): ?PushSubscriptionEntity
    {
        return $this->entityManager->find(PushSubscriptionEntity::class, $id);
    }

    public function findByUser(Uuid $userId): array
    {
        // Bounded even for an owner registered beyond the cap before it existed.
        return $this->entityManager
            ->getRepository(PushSubscriptionEntity::class)
            ->findBy(['userId' => $userId], ['createdAt' => 'DESC', 'id' => 'DESC'], self::SUBSCRIPTIONS_PER_USER);
    }

    private function detachIdentity(string $id): void
    {
        // Native writes must not leave stale managed credentials or initialize
        // references to deleted rows. Detach only the affected identity.
        $entity = $this->entityManager->getUnitOfWork()->tryGetById(['id' => $id], PushSubscriptionEntity::class);
        if ($entity !== false) {
            $this->entityManager->detach($entity);
        }
    }
}
