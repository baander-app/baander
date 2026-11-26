<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Doctrine;

use App\Shared\Application\Exception\MemberInputBoundException;
use App\Shared\Application\Exception\TransactionDeadlockException;
use App\Shared\Infrastructure\Doctrine\DoctrineTransaction;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Proxy\ContextualProxy;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePool;

final class DoctrineTransactionTest extends TestCase
{
    public function testSuccessfulOperationFlushesAndReturnsItsResult(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->once())->method('flush');
        $manager->expects($this->never())->method('clear');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $registry->expects($this->never())->method('resetManager');
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $manager->method('getConnection')->willReturn($connection);

        $result = (new DoctrineTransaction($registry, new Swoole()))->run(static fn (): string => 'committed');

        self::assertSame('committed', $result);
    }

    public function testFailureClearsAnOpenManagerAndPreservesTheOriginalError(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $manager->expects($this->once())->method('clear');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $registry->expects($this->never())->method('resetManager');
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $manager->method('getConnection')->willReturn($connection);
        $error = new \RuntimeException('outbox rejected');

        try {
            (new DoctrineTransaction($registry, new Swoole()))->run(static fn (): never => throw $error);
        } catch (\RuntimeException $caught) {
            self::assertSame($error, $caught);
        }
    }

    public function testADeadlockIsRethrownAsATransactionDeadlockThatKeepsTheDatabaseError(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $manager->expects($this->once())->method('clear');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $registry->expects($this->never())->method('resetManager');
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $manager->method('getConnection')->willReturn($connection);
        $deadlock = new DeadlockException(new class ('deadlock detected', '40P01') extends AbstractException {}, null);

        $caught = null;
        try {
            (new DoctrineTransaction($registry, new Swoole()))->run(static fn (): never => throw $deadlock);
        } catch (TransactionDeadlockException $translated) {
            $caught = $translated;
        }

        self::assertSame($deadlock, $caught->getPrevious(), 'The deadlock reaches the caller as a TransactionDeadlockException.');
    }

    public function testFlushFailureResetsAnOrdinaryClosedManager(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(false);
        $error = new \RuntimeException('flush rejected');
        $manager->method('flush')->willThrowException($error);
        $manager->expects($this->never())->method('clear');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $registry->expects($this->once())->method('resetManager');
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $manager->method('getConnection')->willReturn($connection);

        try {
            (new DoctrineTransaction($registry, new Swoole()))->run(static fn (): null => null);
            self::fail('The flush should fail.');
        } catch (\RuntimeException $caught) {
            self::assertSame($error, $caught);
        }
    }

    public function testFlushFailureReleasesOnlyTheCurrentContextOfAPooledManager(): void
    {
        $manager = $this->createMockForIntersectionOfInterfaces([EntityManagerInterface::class, ContextualProxy::class]);
        $manager->method('isOpen')->willReturn(false);
        $error = new \RuntimeException('flush rejected');
        $manager->method('flush')->willThrowException($error);
        $manager->expects($this->never())->method('clear');
        $swoole = new Swoole();
        $pool = $this->createMock(ServicePool::class);
        $pool->expects($this->once())->method('releaseFromCoroutine')->with($swoole->getCoroutineId());
        $manager->method('getServicePool')->willReturn($pool);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $registry->expects($this->never())->method('resetManager');
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $manager->method('getConnection')->willReturn($connection);

        try {
            (new DoctrineTransaction($registry, $swoole))->run(static fn (): null => null);
            self::fail('The flush should fail.');
        } catch (\RuntimeException $caught) {
            self::assertSame($error, $caught);
        }
    }

    public function testAViolatedMemberInputBoundIsRethrownAsAConflictNamingItsReason(): void
    {
        $violation = self::checkViolation(
            'new row for relation "playlists" violates check constraint "chk_playlists_description_length"'
            . "\nDETAIL:  Failing row contains (0199bf3c-8a00-7000-8000-c4a1d0000001, dddd...).",
        );

        $caught = $this->failureOf($violation);

        self::assertInstanceOf(MemberInputBoundException::class, $caught);
        self::assertSame(['reason' => 'member_input_bound'], $caught->details);
        self::assertSame($violation, $caught->getPrevious());
    }

    /** @return iterable<string, array{string}> */
    public static function checkViolationsThatAreNotAMemberInputBound(): iterable
    {
        yield 'another check' => ['new row for relation "recommendations" violates check constraint "chk_recommendations_origin"'];
        // The failing row is member input; a bound's name in it names no violated constraint.
        yield 'a bound named only in the failing row' => [
            'new row for relation "recommendations" violates check constraint "chk_recommendations_origin"'
            . "\nDETAIL:  Failing row contains (chk_playlists_name_length).",
        ];
    }

    #[DataProvider('checkViolationsThatAreNotAMemberInputBound')]
    public function testAnyOtherCheckViolationIsRethrownUnchanged(string $message): void
    {
        $violation = self::checkViolation($message);

        self::assertSame($violation, $this->failureOf($violation));
    }

    private function failureOf(\Throwable $error): \Throwable
    {
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $manager->method('getConnection')->willReturn($connection);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);

        try {
            (new DoctrineTransaction($registry, new Swoole()))->run(static fn (): never => throw $error);
        } catch (\Throwable $caught) {
            return $caught;
        }
    }

    /** A check violation as DBAL raises it from pdo_pgsql: a generic DriverException. */
    private static function checkViolation(string $message): DriverException
    {
        return new DriverException(new class ('SQLSTATE[23514]: Check violation: 7 ERROR:  ' . $message, '23514') extends AbstractException {}, null);
    }
}
