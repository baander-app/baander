<?php

declare(strict_types=1);

namespace App\Shared\Application\LogViewer\Service;

interface LogSqlExecutorInterface
{
    /**
     * @param list<array<string, mixed>> $records
     * @return array<string, mixed>
     */
    public function run(string $sql, array $records): array;
}
