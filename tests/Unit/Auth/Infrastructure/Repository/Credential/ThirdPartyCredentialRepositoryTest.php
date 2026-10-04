<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Repository\Credential;

use App\Auth\Domain\Model\Credential\ThirdPartyCredential;
use App\Auth\Infrastructure\Doctrine\Entity\ThirdPartyCredentialEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Auth\Infrastructure\Repository\Credential\ThirdPartyCredentialRepository;
use App\Shared\Domain\Model\PublicId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class ThirdPartyCredentialRepositoryTest extends TestCase
{
    public function testSavingExistingCredentialSynchronizesTokensWithoutChangingProvider(): void
    {
        $user = new UserEntity(new PublicId(), 'Credential user', 'credential@baander.app', 'hashed', '');
        $credential = ThirdPartyCredential::create($user->getId(), 'provider', 'new-token', 'refresh', metadata: ['scope' => 'read']);
        $entity = new ThirdPartyCredentialEntity(new PublicId(), $user, 'provider', 'old-token', id: $credential->getId());
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('find')->willReturn($entity);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $manager->expects($this->once())->method('persist')->with($entity);
        $manager->expects($this->once())->method('flush');
        (new ThirdPartyCredentialRepository($manager))->save($credential);
        $this->assertSame('provider', $entity->getProvider());
        $this->assertSame('new-token', $entity->getAccessToken());
        $this->assertSame('refresh', $entity->getRefreshToken());
        $this->assertSame(['scope' => 'read'], $entity->getMeta());
    }
}
