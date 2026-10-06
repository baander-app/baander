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

    public function testRemoveDeletesAndFlushes(): void
    {
        $subscription = new PushSubscriptionEntity(
            userId: new Uuid(),
            endpoint: 'https://push.baander.app/test',
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
        $this->entityManager->expects($this->once())->method('getRepository')
            ->with(PushSubscriptionEntity::class)
            ->willReturn($repo);

        $repo->expects($this->once())->method('findBy')
            ->with(['userId' => $userId]);

        $this->createRepository()->findByUser($userId);
    }

}
