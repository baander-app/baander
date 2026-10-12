<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Repository;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Infrastructure\Repository\OAuth\AccessTokenRepository;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class AccessTokenRepositoryTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private Connection&MockObject $connection;
    private AccessTokenRepository $repository;

    private static ChainId $chainId;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->connection = $this->createMock(Connection::class);
        $this->entityManager->method('getConnection')->willReturn($this->connection);
        $this->repository = new AccessTokenRepository($this->entityManager, new JsonEncoder());

        self::$chainId ??= ChainId::generate();
    }

    // =========================================================================
    // findTokenIdsByChainId: an unlocked read in id order
    // =========================================================================

    public function testFindTokenIdsByChainIdReadsTheChainInIdOrderWithoutLocking(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchFirstColumn')
            ->with(
                'SELECT token_id FROM oauth_access_tokens WHERE chain_id = :chainId ORDER BY id',
                ['chainId' => self::$chainId->toString()],
            )
            ->willReturn(['token-first', 'token-second']);
        $this->connection->expects($this->never())->method('executeStatement');
        $this->entityManager->expects($this->never())->method('clear');

        $this->assertSame(['token-first', 'token-second'], $this->repository->findTokenIdsByChainId(self::$chainId));
    }

    // =========================================================================
    // revokeByChainId: bulk UPDATE
    // =========================================================================

    public function testRevokeByChainIdExecutesBulkUpdate(): void
    {
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE oauth_access_tokens SET revoked = TRUE, updated_at = NOW() WHERE chain_id = :chainId AND revoked = FALSE',
                ['chainId' => self::$chainId->toString()],
            );

        $this->entityManager->expects($this->once())
            ->method('clear');

        $this->repository->revokeByChainId(self::$chainId);
    }

    public function testRevokeByChainIdLocksTheChainRowsInIdOrderBeforeTheUpdate(): void
    {
        $callOrder = [];

        $this->connection->expects($this->once())
            ->method('fetchFirstColumn')
            ->with(
                'SELECT id FROM oauth_access_tokens WHERE chain_id = :chainId ORDER BY id FOR UPDATE',
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

    public function testRevokeByIdsUpdatesTheRowsWithoutClearingTheEntityManager(): void
    {
        $first = Uuid::v7();
        $second = Uuid::v7();
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE oauth_access_tokens SET revoked = TRUE, updated_at = NOW() WHERE id IN (:ids) AND revoked = FALSE',
                ['ids' => [$first->toString(), $second->toString()]],
                ['ids' => ArrayParameterType::STRING],
            )
            ->willReturn(2);
        $this->entityManager->expects($this->never())->method('clear');

        $this->assertSame(2, $this->repository->revokeByIds([$first, $second]));
    }

    public function testRevokeByIdsWithNoIdsTouchesNothing(): void
    {
        $this->connection->expects($this->never())->method('executeStatement');
        $this->entityManager->expects($this->never())->method('clear');

        $this->assertSame(0, $this->repository->revokeByIds([]));
    }

    public function testRevokeByChainIdClearsEntityManager(): void
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

        $this->assertSame(['executeStatement', 'clear'], $callOrder);
    }

    // =========================================================================
    // revokeForUser: bulk UPDATE
    // =========================================================================

    public function testRevokeForUserExecutesBulkUpdate(): void
    {
        $user = User::register(new Email('test@baander.app'), 'hashed', 'Test User');
        $userId = $user->getId();

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE oauth_access_tokens SET revoked = TRUE, updated_at = NOW() WHERE user_id = :userId AND revoked = FALSE',
                ['userId' => $userId->toString()],
            );

        $this->entityManager->expects($this->once())
            ->method('clear');

        $this->repository->revokeForUser($user->getId());
    }

    public function testRevokeForUserClearsEntityManager(): void
    {
        $user = User::register(new Email('test@baander.app'), 'hashed', 'Test User');
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

        $this->repository->revokeForUser($user->getId());

        $this->assertSame(['executeStatement', 'clear'], $callOrder);
    }

    // =========================================================================
    // Edge case: empty results (no tokens) do not error
    // =========================================================================

    public function testRevokeByChainIdWithNoMatchingTokensDoesNotError(): void
    {
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->willReturn(0); // No rows affected

        $this->entityManager->expects($this->once())
            ->method('clear');

        // Should not throw
        $this->repository->revokeByChainId(self::$chainId);
    }

    public function testRevokeForUserWithNoMatchingTokensDoesNotError(): void
    {
        $user = User::register(new Email('test@baander.app'), 'hashed', 'Test User');

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->willReturn(0); // No rows affected

        $this->entityManager->expects($this->once())
            ->method('clear');

        // Should not throw
        $this->repository->revokeForUser($user->getId());
    }
}
