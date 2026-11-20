<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LogViewer\Sql;

use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\LogViewer\Service\LogSqlExecutorInterface;

final class CoroutineSqlExecutor implements LogSqlExecutorInterface
{
    private static int $active = 0;

    /** @param list<array<string, mixed>> $records
     * @return array{columns:list<string>,rows:list<list<mixed>>,truncated:bool}
     */
    public function run(string $sql, array $records): array
    {
        if (self::$active >= 2) {
            throw new ServiceUnavailableException('SQL query capacity is busy.');
        }
        ++self::$active;
        try {
            return new SqlQueryRunner()->run($sql, $records);
        } finally {
            --self::$active;
        }
    }
}
