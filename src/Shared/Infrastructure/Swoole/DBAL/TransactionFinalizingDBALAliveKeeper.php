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

            if ($nestingLevel === 1) {
                try {
                    $this->logger->warning(sprintf(
                        'Connection "%s" had an active transaction at keep-alive. Committing.',
                        $connectionName,
                    ));
                    $connection->commit();
                } catch (Throwable $e) {
                    $this->logger->error(sprintf(
                        'Failed to commit active transaction in connection "%s", rolling back.',
                        $connectionName,
                    ), ['exception' => $e]);
                    $connection->rollBack();
                }
            } else {
                $this->logger->warning(sprintf(
                    'Connection "%s" had an active transaction with nesting level %d at keep-alive. Rolling back.',
                    $connectionName,
                    $nestingLevel,
                ));
                $connection->rollBack();
            }
        }

        $this->decorated->keepAlive($connection, $connectionName);
    }
}
