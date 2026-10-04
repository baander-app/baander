<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\DoctrineTransaction;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
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
}
