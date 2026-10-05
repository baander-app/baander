<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Repository\Passkey;

use App\Auth\Domain\Model\Passkey\Passkey;
use App\Auth\Infrastructure\Doctrine\Entity\PasskeyEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Auth\Infrastructure\Repository\Passkey\PasskeyRepository;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class PasskeyRepositoryTest extends TestCase
{
    public function testSavingNewPasskeyPreservesDomainIdentifier(): void
    {
        $user = new UserEntity(new PublicId(), 'Passkey owner', 'passkey-owner@baander.app', 'unused', '');
        $passkey = Passkey::create(Uuid::v4(), 'Device', 'credential', ['key' => 'value'], 0);

        $passkeyEntities = $this->createMock(EntityRepository::class);
        $passkeyEntities->expects($this->once())->method('find')->with($passkey->getId())->willReturn(null);
        $users = $this->createMock(EntityRepository::class);
        $users->expects($this->once())->method('find')->with($user->getId())->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->exactly(2))->method('getRepository')->willReturnCallback(
            static fn (string $entityClass): EntityRepository => match ($entityClass) {
                PasskeyEntity::class => $passkeyEntities,
                UserEntity::class => $users,
                default => throw new \LogicException(sprintf('Unexpected repository class %s.', $entityClass)),
            },
        );
        $entityManager->expects($this->once())->method('persist')->with(
            $this->callback(static function (PasskeyEntity $entity) use ($passkey): bool {
                self::assertSame($passkey->getId(), $entity->getId());
                return true;
            }),
        );
        $entityManager->expects($this->once())->method('flush');

        (new PasskeyRepository($entityManager))->save($passkey, $user->getId());
    }
}
