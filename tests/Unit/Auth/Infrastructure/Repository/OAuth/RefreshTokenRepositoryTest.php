<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Repository;

use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\RefreshTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Auth\Infrastructure\Repository\OAuth\RefreshTokenRepository;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class RefreshTokenRepositoryTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private Connection&MockObject $connection;
    private RefreshTokenRepository $repository;

    private static Uuid $entityId;
    private static ChainId $chainId;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->connection = $this->createMock(Connection::class);
        $this->entityManager->method('getConnection')->willReturn($this->connection);
        $this->repository = new RefreshTokenRepository($this->entityManager, new JsonEncoder());

        self::$entityId ??= Uuid::v4();
        self::$chainId ??= ChainId::generate();
    }

    // =========================================================================
    // toDomain: one-level predecessor loading (no recursion)
    // =========================================================================

    public function testToDomainLoadsPredecessorWithoutRecursion(): void
    {
        // Build a three-level chain: grandparent -> parent -> current.
        // toDomain() should load parent (one level) but NOT grandparent.
        $clientEntity = $this->createClientEntity();
        $userEntity = $this->createUserEntity();

        $grandparentEntity = $this->createRefreshTokenEntity(
            id: Uuid::v4(),
            tokenId: Uuid::v4()->toString(),
            accessTokenEntity: $this->createAccessTokenEntity($clientEntity, $userEntity),
            chainId: self::$chainId,
        );

        $parentEntity = $this->createRefreshTokenEntity(
            id: Uuid::v4(),
            tokenId: Uuid::v4()->toString(),
            accessTokenEntity: $this->createAccessTokenEntity($clientEntity, $userEntity),
            chainId: self::$chainId,
            previousRefreshToken: $grandparentEntity,
        );

        $currentEntity = $this->createRefreshTokenEntity(
            id: self::$entityId,
            tokenId: self::$entityId->toString(),
            accessTokenEntity: $this->createAccessTokenEntity($clientEntity, $userEntity),
            chainId: self::$chainId,
            previousRefreshToken: $parentEntity,
        );

        // The repository will call find() on the parent entity's ID to load it fresh.
        $refreshTokenRepo = $this->createMock(EntityRepository::class);
        $refreshTokenRepo->method('find')
            ->with($parentEntity->getId())
            ->willReturn($parentEntity);

        $this->entityManager->method('getRepository')
            ->with(RefreshTokenEntity::class)
            ->willReturn($refreshTokenRepo);

        // Invoke toDomain via findByTokenId
        $refreshTokenRepo->method('findOneBy')
            ->with(['tokenId' => self::$entityId->toString()])
            ->willReturn($currentEntity);

        $result = $this->repository->findByTokenId(TokenId::fromString(self::$entityId->toString()));

        $this->assertNotNull($result);
        $this->assertInstanceOf(RefreshToken::class, $result);

        // The predecessor should be loaded
        $predecessor = $result->getPreviousRefreshToken();
        $this->assertNotNull($predecessor);

        // Critical: the predecessor's predecessor must be null (no recursion).
        $this->assertNull($predecessor->getPreviousRefreshToken());
    }

    public function testToDomainNoPredecessorReturnsNull(): void
    {
        $clientEntity = $this->createClientEntity();
        $userEntity = $this->createUserEntity();

        $currentEntity = $this->createRefreshTokenEntity(
            id: self::$entityId,
            tokenId: self::$entityId->toString(),
            accessTokenEntity: $this->createAccessTokenEntity($clientEntity, $userEntity),
            chainId: self::$chainId,
            previousRefreshToken: null,
        );

        $refreshTokenRepo = $this->createMock(EntityRepository::class);
        $refreshTokenRepo->method('findOneBy')
            ->with(['tokenId' => self::$entityId->toString()])
            ->willReturn($currentEntity);

        $this->entityManager->method('getRepository')
            ->with(RefreshTokenEntity::class)
            ->willReturn($refreshTokenRepo);

        $result = $this->repository->findByTokenId(TokenId::fromString(self::$entityId->toString()));

        $this->assertNotNull($result);
        $this->assertNull($result->getPreviousRefreshToken());
    }

    public function testToDomainPredecessorNotFoundReturnsNull(): void
    {
        $clientEntity = $this->createClientEntity();
        $userEntity = $this->createUserEntity();

        $parentId = Uuid::v4();
        $parentEntity = $this->createRefreshTokenEntity(
            id: $parentId,
            tokenId: Uuid::v4()->toString(),
            accessTokenEntity: $this->createAccessTokenEntity($clientEntity, $userEntity),
            chainId: self::$chainId,
        );

        $currentEntity = $this->createRefreshTokenEntity(
            id: self::$entityId,
            tokenId: self::$entityId->toString(),
            accessTokenEntity: $this->createAccessTokenEntity($clientEntity, $userEntity),
            chainId: self::$chainId,
            previousRefreshToken: $parentEntity,
        );

        // Simulate the predecessor entity being deleted from DB after association.
        $refreshTokenRepo = $this->createMock(EntityRepository::class);
        $refreshTokenRepo->method('findOneBy')
            ->with(['tokenId' => self::$entityId->toString()])
            ->willReturn($currentEntity);
        $refreshTokenRepo->method('find')
            ->with($parentId)
            ->willReturn(null);

        $this->entityManager->method('getRepository')
            ->with(RefreshTokenEntity::class)
            ->willReturn($refreshTokenRepo);

        $result = $this->repository->findByTokenId(TokenId::fromString(self::$entityId->toString()));

        $this->assertNotNull($result);
        $this->assertNull($result->getPreviousRefreshToken());
    }

    // =========================================================================
    // toDomain: null chainId is preserved
    // =========================================================================

    public function testToDomainPreservesNullChainId(): void
    {
        $clientEntity = $this->createClientEntity();
        $userEntity = $this->createUserEntity();

        $entity = $this->createRefreshTokenEntity(
            id: self::$entityId,
            tokenId: self::$entityId->toString(),
            accessTokenEntity: $this->createAccessTokenEntity($clientEntity, $userEntity),
            chainId: null,
            previousRefreshToken: null,
        );

        $refreshTokenRepo = $this->createMock(EntityRepository::class);
        $refreshTokenRepo->method('findOneBy')
            ->with(['tokenId' => self::$entityId->toString()])
            ->willReturn($entity);

        $this->entityManager->method('getRepository')
            ->with(RefreshTokenEntity::class)
            ->willReturn($refreshTokenRepo);

        $result = $this->repository->findByTokenId(TokenId::fromString(self::$entityId->toString()));

        $this->assertNotNull($result);
        // The chainId must be null -- NOT a randomly generated value.
        $this->assertNull($result->getChainId());
    }

    public function testToDomainWithNonNullChainIdPreservesValue(): void
    {
        $clientEntity = $this->createClientEntity();
        $userEntity = $this->createUserEntity();
        $chainId = ChainId::generate();

        $entity = $this->createRefreshTokenEntity(
            id: self::$entityId,
            tokenId: self::$entityId->toString(),
            accessTokenEntity: $this->createAccessTokenEntity($clientEntity, $userEntity),
            chainId: $chainId,
            previousRefreshToken: null,
        );

        $refreshTokenRepo = $this->createMock(EntityRepository::class);
        $refreshTokenRepo->method('findOneBy')
            ->with(['tokenId' => self::$entityId->toString()])
            ->willReturn($entity);

        $this->entityManager->method('getRepository')
            ->with(RefreshTokenEntity::class)
            ->willReturn($refreshTokenRepo);

        $result = $this->repository->findByTokenId(TokenId::fromString(self::$entityId->toString()));

        $this->assertNotNull($result);
        $this->assertNotNull($result->getChainId());
        $this->assertTrue($chainId->equals($result->getChainId()));
    }

    // =========================================================================
    // revokeByChainId: bulk UPDATE
    // =========================================================================

    public function testRevokeByChainIdExecutesBulkUpdate(): void
    {
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE oauth_refresh_tokens SET revoked = TRUE, updated_at = NOW() WHERE chain_id = :chainId AND revoked = FALSE',
                ['chainId' => self::$chainId->toString()],
            );

        $this->entityManager->expects($this->once())
            ->method('clear');

        $this->repository->revokeByChainId(self::$chainId);
    }

    public function testRevokeByChainIdClearsEntityManagerAfterUpdate(): void
    {
        $callOrder = [];

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function () use (&$callOrder): int {
                $callOrder[] = 'executeStatement';
                return 1;
            });

        $this->entityManager->expects($this->once())
            ->method('clear')
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'clear';
            });

        $this->repository->revokeByChainId(self::$chainId);

        // executeStatement must be called before clear
        $this->assertSame(['executeStatement', 'clear'], $callOrder);
    }

    // =========================================================================
    // Helper methods to create Doctrine entity instances
    // =========================================================================

    private function createClientEntity(): ClientEntity
    {
        return new ClientEntity(
            new PublicId(),
            'Test Client',
            '["http://localhost"]',
        );
    }

    private function createUserEntity(): UserEntity
    {
        return new UserEntity(
            new PublicId(),
            'Test User',
            'test@example.com',
            'hashed-password',
            '',
        );
    }

    private function createAccessTokenEntity(
        ClientEntity $client,
        ?UserEntity $user = null,
        ?ChainId $chainId = null,
    ): AccessTokenEntity {
        return new AccessTokenEntity(
            Uuid::v4()->toString(),
            $client,
            $user,
            null,
            ['read'],
            null,
            $chainId?->toString(),
        );
    }

    private function createRefreshTokenEntity(
        Uuid $id,
        string $tokenId,
        AccessTokenEntity $accessTokenEntity,
        ?ChainId $chainId = null,
        ?RefreshTokenEntity $previousRefreshToken = null,
    ): RefreshTokenEntity {
        $entity = new RefreshTokenEntity(
            $tokenId,
            $accessTokenEntity,
            null,
            $chainId?->toString(),
            $previousRefreshToken,
        );

        // Use reflection to set the ID since the constructor generates a random one
        $ref = new \ReflectionProperty(RefreshTokenEntity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);

        return $entity;
    }
}
