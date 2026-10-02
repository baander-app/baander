<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole\DBAL;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use App\Shared\Infrastructure\Swoole\DBAL\TransactionFinalizingDBALAliveKeeper;
use SwooleBundle\SwooleBundle\Bridge\Doctrine\DBAL\DBALAliveKeeper;

final class TransactionFinalizingDBALAliveKeeperTest extends TestCase
{
    private Connection&MockObject $connection;
    private DBALAliveKeeper&MockObject $decorated;
    private LoggerInterface $logger;
    private TransactionFinalizingDBALAliveKeeper $keeper;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->decorated = $this->createMock(DBALAliveKeeper::class);
        $this->logger = $this->createStub(LoggerInterface::class);

        $this->keeper = new TransactionFinalizingDBALAliveKeeper(
            decorated: $this->decorated,
            logger: $this->logger,
        );
    }

    public function testKeepAliveRollsBackActiveTransactionInsteadOfCommitting(): void
    {
        $this->connection->method('isTransactionActive')->willReturn(true);
        $this->connection->method('getTransactionNestingLevel')->willReturn(1);

        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');

        $this->decorated->expects($this->once())->method('keepAlive');

        $this->keeper->keepAlive($this->connection, 'default');
    }

    public function testKeepAliveRollsBackNestedActiveTransaction(): void
    {
        $this->connection->method('isTransactionActive')->willReturn(true);
        $this->connection->method('getTransactionNestingLevel')->willReturn(3);

        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');

        $this->decorated->expects($this->once())->method('keepAlive');

        $this->keeper->keepAlive($this->connection, 'default');
    }

    public function testKeepAliveCallsDecoratedWhenNoTransactionIsActive(): void
    {
        $this->connection->method('isTransactionActive')->willReturn(false);

        $this->connection->expects($this->never())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');

        $this->decorated->expects($this->once())->method('keepAlive');

        $this->keeper->keepAlive($this->connection, 'default');
    }
}
