<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Push;

use App\Notification\Application\DTO\PushSubscriptionRegistration;
use App\Notification\Application\DTO\PushSubscriptionRegistrationResult;
use App\Notification\Application\Port\PushSubscriptionRegistrationPortInterface;
use App\Notification\Application\Port\PushSubscriptionRemovalPortInterface;
use App\Notification\Infrastructure\Doctrine\Entity\PushSubscriptionEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class PushSubscriptionRepository implements PushSubscriptionRepositoryInterface, PushSubscriptionRegistrationPortInterface, PushSubscriptionRemovalPortInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function registerForUser(Uuid $ownerId, PushSubscriptionRegistration $subscription): PushSubscriptionRegistrationResult
    {
        $row = $this->entityManager->getConnection()->executeQuery(
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
            return PushSubscriptionRegistrationResult::Conflict;
        }
        $this->detachIdentity((string) $row['id']);

        return $row['previous_id'] === null
            ? PushSubscriptionRegistrationResult::Created
            : PushSubscriptionRegistrationResult::Updated;
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

    public function findByUser(Uuid $userId): array
    {
        return $this->entityManager
            ->getRepository(PushSubscriptionEntity::class)
            ->findBy(['userId' => $userId]);
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
