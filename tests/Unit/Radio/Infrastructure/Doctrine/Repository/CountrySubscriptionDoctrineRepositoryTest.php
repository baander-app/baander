<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Infrastructure\Doctrine\Repository;

use App\Radio\Domain\Model\CountrySubscription\CountrySubscription;
use App\Radio\Infrastructure\Doctrine\Entity\CountrySubscriptionEntity;
use App\Radio\Infrastructure\Doctrine\Entity\RadioSourceEntity;
use App\Radio\Infrastructure\Doctrine\Repository\CountrySubscriptionDoctrineRepository;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class CountrySubscriptionDoctrineRepositoryTest extends TestCase
{
    public function testNewSubscriptionStoresScalarOwnerAndMappedSourceReference(): void
    {
        $userId = Uuid::generate();
        $source = new RadioSourceEntity(Uuid::generate(), 'Radio source', 'radio-browser', 'https://radio.baander.app');
        $subscription = CountrySubscription::create($userId, $source->getId(), 'DK');
        $synced = new \DateTimeImmutable('2026-10-04T12:00:00Z');
        $subscription->markSynced($synced);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('find')->willReturn(null);
        $manager->expects($this->once())->method('getReference')
            ->with(RadioSourceEntity::class, $source->getId())
            ->willReturn($source);
        $manager->expects($this->once())->method('persist')->with($this->callback(
            fn (CountrySubscriptionEntity $entity): bool => $entity->getId()->equals($subscription->getId())
                && $entity->getUserId()->equals($userId)
                && $entity->getSourceId()->equals($source->getId())
                && $entity->getCountryCode() === 'DK'
                && $entity->getLastSyncedAt() === $synced,
        ));
        $manager->expects($this->once())->method('flush');
        (new CountrySubscriptionDoctrineRepository($manager))->save($subscription);
    }
}
