<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Query;

use Doctrine\DBAL\Connection;
use Throwable;

final class AvailabilityReadCursor
{
    /**
     * @param array<string|int, mixed> $parameters
     * @param array<string|int, mixed> $types
     * @return iterable<list<array<string, mixed>>>
     */
    public static function batches(
        Connection $connection,
        string $sql,
        array $parameters,
        array $types,
        int $size,
    ): iterable {
        $cursor = 'availability_scan_' . bin2hex(random_bytes(8));
        $ownsTransaction = !$connection->isTransactionActive();
        if ($ownsTransaction) {
            $connection->beginTransaction();
        }
        $opened = false;
        $complete = false;
        try {
            // DQL has no server cursor. The transaction pins PgBouncer's backend; bounded FETCH avoids PDO's full-result buffer.
            $connection->executeStatement('DECLARE ' . $cursor . ' NO SCROLL CURSOR FOR ' . $sql, $parameters, $types);
            $opened = true;
            while (true) {
                $rows = $connection->fetchAllAssociative('FETCH FORWARD ' . $size . ' FROM ' . $cursor);
                if ($rows === []) {
                    break;
                }
                yield $rows;
                if (count($rows) < $size) {
                    break;
                }
            }
            $complete = true;
        } finally {
            $closed = !$opened;
            try {
                if ($opened) {
                    try {
                        $connection->executeStatement('CLOSE ' . $cursor);
                        $closed = true;
                    } catch (Throwable $cleanupError) {
                        // PostgreSQL refuses CLOSE in an aborted transaction; retain the unfinished scan's original failure.
                        if ($complete) {
                            throw $cleanupError;
                        }
                    }
                }
            } finally {
                if ($ownsTransaction && $connection->isTransactionActive()) {
                    if ($complete && $closed) {
                        $connection->commit();
                    } else {
                        $connection->rollBack();
                    }
                }
            }
        }
    }
}
