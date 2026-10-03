<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\RefreshTokenEntity;
use App\Auth\Infrastructure\Repository\OAuth\AccessTokenRepository;
use App\Auth\Infrastructure\Repository\OAuth\RefreshTokenRepository;
use App\Shared\Domain\Model\PublicId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class RefreshTokenDpopMappingTest extends TestCase
{
    #[DataProvider('bindings')]
    public function testLoadingRefreshAndSavingRevokedAccessPreservesBinding(?string $jkt): void
    {
        $client = new ClientEntity(new PublicId(), 'Mapping client', '["https://baander.app/callback"]');
        $access = new AccessTokenEntity(bin2hex(random_bytes(40)), $client);
        $access->setDpopJkt($jkt);
        $refresh = new RefreshTokenEntity(bin2hex(random_bytes(40)), $access);
        $refreshEntities = $this->createMock(EntityRepository::class);
        $refreshEntities->expects(self::once())->method('findOneBy')->with(['tokenId' => $refresh->getTokenId()])->willReturn($refresh);
        $accessEntities = $this->createMock(EntityRepository::class);
        $accessEntities->expects(self::once())->method('find')->with($access->getId())->willReturn($access);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturnMap([
            [RefreshTokenEntity::class, $refreshEntities], [AccessTokenEntity::class, $accessEntities],
        ]);
        $manager->expects(self::once())->method('persist')->with($access);
        $manager->expects(self::never())->method('flush');
        $loaded = new RefreshTokenRepository($manager, new JsonEncoder())->findByTokenId(TokenId::fromString($refresh->getTokenId()));

        self::assertNotNull($loaded);
        $accessToken = $loaded->getAccessToken();
        self::assertSame($jkt, $accessToken->getDpopJkt());
        $accessToken->revoke();
        new AccessTokenRepository($manager, new JsonEncoder())->save($accessToken, false);
        self::assertTrue($access->isRevoked());
        self::assertSame($jkt, $access->getDpopJkt());
    }

    public function testPredecessorAccessBindingIsAlsoPreserved(): void
    {
        $client = new ClientEntity(new PublicId(), 'Mapping client', '["https://baander.app/callback"]');
        $previousAccess = new AccessTokenEntity(bin2hex(random_bytes(40)), $client);
        $previousAccess->setDpopJkt(str_repeat('b', 43));
        $previous = new RefreshTokenEntity(bin2hex(random_bytes(40)), $previousAccess);
        $access = new AccessTokenEntity(bin2hex(random_bytes(40)), $client);
        $access->setDpopJkt(str_repeat('a', 43));
        $refresh = new RefreshTokenEntity(bin2hex(random_bytes(40)), $access, previousRefreshToken: $previous);
        $entities = $this->createMock(EntityRepository::class);
        $entities->expects(self::once())->method('findOneBy')->with(['tokenId' => $refresh->getTokenId()])->willReturn($refresh);
        $entities->expects(self::once())->method('find')->with($previous->getId())->willReturn($previous);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::exactly(2))->method('getRepository')->with(RefreshTokenEntity::class)->willReturn($entities);
        $manager->expects(self::never())->method('persist');
        $manager->expects(self::never())->method('flush');

        $loaded = new RefreshTokenRepository($manager, new JsonEncoder())->findByTokenId(TokenId::fromString($refresh->getTokenId()));

        self::assertNotNull($loaded);
        self::assertSame(str_repeat('a', 43), $loaded->getAccessToken()->getDpopJkt());
        $predecessor = $loaded->getPreviousRefreshToken();
        self::assertNotNull($predecessor);
        self::assertSame(str_repeat('b', 43), $predecessor->getAccessToken()->getDpopJkt());
        self::assertNull($predecessor->getPreviousRefreshToken());
    }

    /** @return iterable<string, array{?string}> */
    public static function bindings(): iterable
    {
        yield 'bound' => [str_repeat('a', 43)];
        yield 'unbound remains unbound' => [null];
    }
}
