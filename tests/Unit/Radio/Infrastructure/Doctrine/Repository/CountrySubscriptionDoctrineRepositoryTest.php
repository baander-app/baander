<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Infrastructure\Doctrine\Repository;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Radio\Domain\Model\CountrySubscription\CountrySubscription;
use App\Radio\Infrastructure\Doctrine\Entity\CountrySubscriptionEntity;
use App\Radio\Infrastructure\Doctrine\Entity\RadioSourceEntity;
use App\Radio\Infrastructure\Doctrine\Repository\CountrySubscriptionDoctrineRepository;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class CountrySubscriptionDoctrineRepositoryTest extends TestCase
{
    public function testNewSubscriptionUsesMappedUserAndSourceReferences(): void
    {
        $user = new UserEntity(new PublicId(), 'Radio user', 'radio@baander.app', 'hashed', '');
        $source = new RadioSourceEntity(Uuid::generate(), 'Radio source', 'radio-browser', 'https://radio.baander.app');
        $subscription = CountrySubscription::create($user->getId(), $source->getId(), 'DK');
        $synced = new \DateTimeImmutable('2026-10-04T12:00:00Z');
        $subscription->markSynced($synced);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('find')->willReturn(null);
        $manager->expects($this->exactly(2))->method('getReference')->willReturnCallback(
            function (string $class, mixed $id) use ($user, $source): object {
                if ($class === UserEntity::class) {
                    $this->assertEquals($user->getId(), $id);
                    return $user;
                }
                $this->assertSame(RadioSourceEntity::class, $class);
                $this->assertEquals($source->getId(), $id);
                return $source;
            },
        );
        $manager->expects($this->once())->method('persist')->with($this->callback(
            fn (CountrySubscriptionEntity $entity): bool => $entity->getId()->equals($subscription->getId())
                && $entity->getUserId()->equals($user->getId())
                && $entity->getSourceId()->equals($source->getId())
                && $entity->getCountryCode() === 'DK'
                && $entity->getLastSyncedAt() === $synced,
        ));
        $manager->expects($this->once())->method('flush');
        (new CountrySubscriptionDoctrineRepository($manager))->save($subscription);
    }
}
