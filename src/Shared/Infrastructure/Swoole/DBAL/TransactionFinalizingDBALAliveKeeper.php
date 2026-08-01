<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\DBAL;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use SwooleBundle\SwooleBundle\Bridge\Doctrine\DBAL\DBALAliveKeeper;
use Throwable;

final readonly class TransactionFinalizingDBALAliveKeeper implements DBALAliveKeeper
{
    public function __construct(
        private DBALAliveKeeper $decorated,
        private LoggerInterface $logger,
    )
    {
    }

    public function keepAlive(Connection $connection, string $connectionName): void
    {
        if ($connection->isTransactionActive()) {
            $nestingLevel = $connection->getTransactionNestingLevel();

            $this->logger->warning(sprintf(
                'Connection "%s" had an active transaction at keep-alive (nesting level %d). Rolling back.',
                $connectionName,
                $nestingLevel,
            ));

            try {
                $connection->rollBack();
            } catch (Throwable $e) {
                $this->logger->error(sprintf(
                    'Failed to roll back active transaction in connection "%s".',
                    $connectionName,
                ), ['exception' => $e]);
            }
        }

        $this->decorated->keepAlive($connection, $connectionName);
    }
}
