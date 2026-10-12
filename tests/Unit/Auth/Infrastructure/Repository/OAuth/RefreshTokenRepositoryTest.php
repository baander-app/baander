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
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class RefreshTokenRepositoryTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private Connection $connection;
    private RefreshTokenRepository $repository;

    private static Uuid $entityId;
    private static ChainId $chainId;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->connection = $this->createStub(Connection::class);
        $this->entityManager->method('getConnection')->willReturnCallback(fn (): Connection => $this->connection);
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
        $refreshTokenRepo->expects($this->once())->method('find')
            ->with($parentEntity->getId())
            ->willReturn($parentEntity);

        $this->entityManager->expects($this->atLeastOnce())->method('getRepository')
            ->with(RefreshTokenEntity::class)
            ->willReturn($refreshTokenRepo);

        // Invoke toDomain via findByTokenId
        $refreshTokenRepo->expects($this->once())->method('findOneBy')
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
        $refreshTokenRepo->expects($this->once())->method('findOneBy')
            ->with(['tokenId' => self::$entityId->toString()])
            ->willReturn($currentEntity);

        $this->entityManager->expects($this->atLeastOnce())->method('getRepository')
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
        $refreshTokenRepo->expects($this->once())->method('findOneBy')
            ->with(['tokenId' => self::$entityId->toString()])
            ->willReturn($currentEntity);
        $refreshTokenRepo->expects($this->once())->method('find')
            ->with($parentId)
            ->willReturn(null);

        $this->entityManager->expects($this->atLeastOnce())->method('getRepository')
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
        $refreshTokenRepo->expects($this->once())->method('findOneBy')
            ->with(['tokenId' => self::$entityId->toString()])
            ->willReturn($entity);

        $this->entityManager->expects($this->atLeastOnce())->method('getRepository')
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
        $refreshTokenRepo->expects($this->once())->method('findOneBy')
            ->with(['tokenId' => self::$entityId->toString()])
            ->willReturn($entity);

        $this->entityManager->expects($this->atLeastOnce())->method('getRepository')
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
        $this->connection = $this->createMock(Connection::class);

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
        $this->connection = $this->createMock(Connection::class);

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

    public function testRevokeByChainIdLocksTheChainRowsInIdOrderBeforeTheUpdate(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $callOrder = [];

        $this->connection->expects($this->once())
            ->method('fetchFirstColumn')
            ->with(
                'SELECT id FROM oauth_refresh_tokens WHERE chain_id = :chainId ORDER BY id FOR UPDATE',
                ['chainId' => self::$chainId->toString()],
            )
            ->willReturnCallback(function () use (&$callOrder): array {
                $callOrder[] = 'lock';
                return [];
            });
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function () use (&$callOrder): int {
                $callOrder[] = 'update';
                return 0;
            });
        $this->entityManager->expects($this->once())
            ->method('clear')
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'clear';
            });

        $this->repository->revokeByChainId(self::$chainId);

        $this->assertSame(['lock', 'update', 'clear'], $callOrder);
    }

    // =========================================================================
    // Locked reads and revocation by id for the lost-response grace
    // =========================================================================

    public function testLockByTokenIdLocksTheRowAndReturnsItsRefreshedState(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $entity = $this->createRefreshTokenEntity(
            id: self::$entityId,
            tokenId: self::$entityId->toString(),
            accessTokenEntity: $this->createAccessTokenEntity($this->createClientEntity(), $this->createUserEntity()),
            chainId: self::$chainId,
        );
        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT id FROM oauth_refresh_tokens WHERE token_id = :tokenId FOR UPDATE', ['tokenId' => self::$entityId->toString()])
            ->willReturn(self::$entityId->toString());
        $refreshTokenRepo = $this->createMock(EntityRepository::class);
        $refreshTokenRepo->expects($this->once())->method('find')->with(self::$entityId)->willReturn($entity);
        $this->entityManager->expects($this->atLeastOnce())->method('getRepository')->with(RefreshTokenEntity::class)->willReturn($refreshTokenRepo);
        // The identity map may hold the row as it was before the lock; the lock waited for a commit.
        $refreshed = [];
        $this->entityManager->expects($this->exactly(2))->method('refresh')->willReturnCallback(function (object $object) use (&$refreshed): void {
            $refreshed[] = $object;
        });
        $this->entityManager->expects($this->never())->method('clear');

        $result = $this->repository->lockByTokenId(TokenId::fromString(self::$entityId->toString()));

        $this->assertNotNull($result);
        $this->assertTrue($result->getId()->equals(self::$entityId));
        $this->assertSame([$entity, $entity->getAccessToken()], $refreshed, 'The row and its access token are re-read under the lock.');
    }

    public function testLockByTokenIdReturnsNullForAnUnknownToken(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->connection->expects($this->once())->method('fetchOne')->willReturn(false);
        $this->entityManager->expects($this->never())->method('getRepository');

        $this->assertNull($this->repository->lockByTokenId(TokenId::fromString(self::$entityId->toString())));
    }

    public function testLockSuccessorsLocksThemInIdOrderAndReturnsThemRefreshed(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $clientEntity = $this->createClientEntity();
        $userEntity = $this->createUserEntity();
        $first = $this->createRefreshTokenEntity(Uuid::v7(), Uuid::v4()->toString(), $this->createAccessTokenEntity($clientEntity, $userEntity), self::$chainId);
        $second = $this->createRefreshTokenEntity(Uuid::v7(), Uuid::v4()->toString(), $this->createAccessTokenEntity($clientEntity, $userEntity), self::$chainId);
        $this->connection->expects($this->once())
            ->method('fetchFirstColumn')
            ->with(
                'SELECT id FROM oauth_refresh_tokens WHERE previous_refresh_token_id = :id ORDER BY id FOR UPDATE',
                ['id' => self::$entityId->toString()],
            )
            ->willReturn([$first->getId()->toString(), $second->getId()->toString()]);
        $refreshTokenRepo = $this->createStub(EntityRepository::class);
        $refreshTokenRepo->method('find')->willReturnCallback(
            static fn (Uuid $id): ?RefreshTokenEntity => $id->equals($first->getId()) ? $first : ($id->equals($second->getId()) ? $second : null),
        );
        $this->entityManager->expects($this->atLeastOnce())->method('getRepository')->with(RefreshTokenEntity::class)->willReturn($refreshTokenRepo);
        $this->entityManager->expects($this->exactly(4))->method('refresh');
        $this->entityManager->expects($this->never())->method('clear');

        $result = $this->repository->lockSuccessors(self::$entityId);

        $this->assertCount(2, $result);
        $this->assertTrue($result[0]->getId()->equals($first->getId()));
        $this->assertTrue($result[1]->getId()->equals($second->getId()));
    }

    public function testLockSuccessorsOfATokenWithoutSuccessorsIsEmpty(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->connection->expects($this->once())->method('fetchFirstColumn')->willReturn([]);
        $this->entityManager->expects($this->never())->method('getRepository');

        $this->assertSame([], $this->repository->lockSuccessors(self::$entityId));
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function windowAnswers(): iterable
    {
        yield 'consumed within the window' => [1, true];
        yield 'consumed earlier' => [0, false];
        yield 'never consumed' => [null, false];
        yield 'no such row' => [false, false];
    }

    /** The window is one predicate on the database clock: used_at >= NOW() - the window. */
    #[DataProvider('windowAnswers')]
    public function testConsumedWithinAsksTheDatabaseClock(mixed $answer, bool $expected): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->with(
                'SELECT (used_at >= NOW() - make_interval(secs => :seconds))::int FROM oauth_refresh_tokens WHERE id = :id',
                ['id' => self::$entityId->toString(), 'seconds' => 60],
                ['seconds' => ParameterType::INTEGER],
            )
            ->willReturn($answer);
        $this->entityManager->expects($this->never())->method('clear');

        $this->assertSame($expected, $this->repository->consumedWithin(self::$entityId, 60));
    }

    public function testChainHoldsLiveTokenAsksForAnUnrevokedRowOfTheChain(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->with(
                'SELECT (EXISTS (SELECT 1 FROM oauth_refresh_tokens WHERE chain_id = :chainId AND revoked = FALSE))::int',
                ['chainId' => self::$chainId->toString()],
            )
            ->willReturn(1);
        $this->entityManager->expects($this->never())->method('clear');

        $this->assertTrue($this->repository->chainHoldsLiveToken(self::$chainId));
    }

    public function testRevokeByIdsUpdatesTheRowsWithoutClearingTheEntityManager(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $first = Uuid::v7();
        $second = Uuid::v7();
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE oauth_refresh_tokens SET revoked = TRUE, updated_at = NOW() WHERE id IN (:ids) AND revoked = FALSE',
                ['ids' => [$first->toString(), $second->toString()]],
                ['ids' => ArrayParameterType::STRING],
            )
            ->willReturn(2);
        $this->entityManager->expects($this->never())->method('clear');

        $this->assertSame(2, $this->repository->revokeByIds([$first, $second]));
    }

    public function testRevokeByIdsWithNoIdsTouchesNothing(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->connection->expects($this->never())->method('executeStatement');
        $this->entityManager->expects($this->never())->method('clear');

        $this->assertSame(0, $this->repository->revokeByIds([]));
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
            'test@baander.app',
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
            $chainId?->getUuid(),
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
            $chainId?->getUuid(),
            $previousRefreshToken,
        );

        // Use reflection to set the ID since the constructor generates a random one
        $ref = new \ReflectionProperty(RefreshTokenEntity::class, 'id');
        $ref->setValue($entity, $id);

        return $entity;
    }
}
