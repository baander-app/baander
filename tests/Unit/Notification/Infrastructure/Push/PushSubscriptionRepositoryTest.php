<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Push;

use App\Notification\Infrastructure\Doctrine\Entity\PushSubscriptionEntity;
use App\Notification\Infrastructure\Push\PushSubscriptionRepository;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PushSubscriptionRepositoryTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
    }

    private function createRepository(): PushSubscriptionRepository
    {
        return new PushSubscriptionRepository($this->entityManager);
    }

    public function testSavePersistsAndFlushes(): void
    {
        $userId = new Uuid();
        $subscription = new PushSubscriptionEntity(
            userId: $userId,
            endpoint: 'https://fcm.googleapis.com/test',
            publicKey: 'pk',
            authKey: 'ak',
            contentEncoding: 'aes128gcm',
        );

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $this->createRepository()->save($subscription);
    }

    public function testRemoveDeletesAndFlushes(): void
    {
        $userId = new Uuid();
        $subscription = new PushSubscriptionEntity(
            userId: $userId,
            endpoint: 'https://fcm.googleapis.com/test',
            publicKey: 'pk',
            authKey: 'ak',
            contentEncoding: 'aes128gcm',
        );

        $this->entityManager->expects($this->once())->method('remove');
        $this->entityManager->expects($this->once())->method('flush');

        $this->createRepository()->remove($subscription);
    }

    public function testFindByUserQueriesRepository(): void
    {
        $userId = new Uuid();

        $repo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $this->entityManager->method('getRepository')
            ->with(PushSubscriptionEntity::class)
            ->willReturn($repo);

        $repo->expects($this->once())->method('findBy')
            ->with(['userId' => $userId]);

        $this->createRepository()->findByUser($userId);
    }

    public function testFindByEndpointQueriesRepository(): void
    {
        $endpoint = 'https://fcm.googleapis.com/test';

        $repo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $this->entityManager->method('getRepository')
            ->with(PushSubscriptionEntity::class)
            ->willReturn($repo);

        $repo->expects($this->once())->method('findOneBy')
            ->with(['endpoint' => $endpoint]);

        $this->createRepository()->findByEndpoint($endpoint);
    }

    public function testRemoveByEndpointRemovesIfFound(): void
    {
        $userId = new Uuid();
        $endpoint = 'https://fcm.googleapis.com/test';
        $subscription = new PushSubscriptionEntity(
            userId: $userId,
            endpoint: $endpoint,
            publicKey: 'pk',
            authKey: 'ak',
            contentEncoding: 'aes128gcm',
        );

        $repo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $this->entityManager->method('getRepository')
            ->willReturn($repo);
        $repo->method('findOneBy')
            ->with(['endpoint' => $endpoint])
            ->willReturn($subscription);

        $this->entityManager->expects($this->once())->method('remove');

        $this->createRepository()->removeByEndpoint($endpoint);
    }

    public function testRemoveByEndpointDoesNothingIfNotFound(): void
    {
        $repo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $this->entityManager->method('getRepository')
            ->willReturn($repo);
        $repo->method('findOneBy')->willReturn(null);

        $this->entityManager->expects($this->never())->method('remove');

        $this->createRepository()->removeByEndpoint('https://fcm.googleapis.com/nonexistent');
    }
}
