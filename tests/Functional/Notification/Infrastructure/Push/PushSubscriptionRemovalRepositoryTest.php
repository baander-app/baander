<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification\Infrastructure\Push;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Notification\Application\Port\PushSubscriptionRemovalPortInterface;
use App\Notification\Infrastructure\Doctrine\Entity\PushSubscriptionEntity;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\TestCase;

final class PushSubscriptionRemovalRepositoryTest extends TestCase
{
    public function testOwnerScopedDeletePreservesOtherSubscriptionsAndPendingWorkAcrossHydration(): void
    {
        $owner = new UserEntity(new PublicId(), 'Stored name', 'push-owner@baander.app', 'test-only', '');
        $other = new UserEntity(new PublicId(), 'Other', 'push-other@baander.app', 'test-only', '');
        $target = new PushSubscriptionEntity($owner->getId(), 'https://push.baander.app/target', 'pk', 'ak', 'aes128gcm');
        $retained = new PushSubscriptionEntity($owner->getId(), 'https://push.baander.app/retained', 'pk', 'ak', 'aes128gcm');
        $foreign = new PushSubscriptionEntity($other->getId(), 'https://push.baander.app/foreign', 'pk', 'ak', 'aes128gcm');
        foreach ([$owner, $other, $target, $retained, $foreign] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();
        $managed = $this->entityManager->find(PushSubscriptionEntity::class, $target->getId());
        $this->assertNotNull($managed);
        $managedOwner = $this->entityManager->find(UserEntity::class, $owner->getId());
        $this->assertNotNull($managedOwner);
        $managedOwner->setName('Pending name');
        $repository = static::getContainer()->get(PushSubscriptionRemovalPortInterface::class);
        $repository->removeForUser($other->getId(), $target->getEndpoint());
        $this->assertTrue($this->entityManager->contains($managed));
        $repository->removeForUser($owner->getId(), $target->getEndpoint());
        $this->assertFalse($this->entityManager->contains($managed));
        $repository->removeForUser($owner->getId(), $target->getEndpoint());
        $repository->removeForUser($owner->getId(), $foreign->getEndpoint());
        $connection = $this->entityManager->getConnection();
        $this->assertSame('Stored name', $connection->fetchOne('SELECT name FROM users WHERE id = :id', ['id' => $owner->getId()->toString()]));
        $this->assertSame('Pending name', $managedOwner->getName());
        $this->assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM push_subscriptions WHERE user_id IN (:owner, :other)', [
            'owner' => $owner->getId()->toString(), 'other' => $other->getId()->toString(),
        ]));
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->assertNull($this->entityManager->find(PushSubscriptionEntity::class, $target->getId()));
        $this->assertNotNull($this->entityManager->find(PushSubscriptionEntity::class, $retained->getId()));
        $this->assertNotNull($this->entityManager->find(PushSubscriptionEntity::class, $foreign->getId()));
        $this->assertSame('Pending name', $connection->fetchOne('SELECT name FROM users WHERE id = :id', ['id' => $owner->getId()->toString()]));
    }
}
