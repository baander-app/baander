<?php

declare(strict_types=1);

namespace App\Shared\Application\LogViewer;

use App\Shared\Application\Exception\InvalidInputException;

final readonly class LogSqlQuery
{
    public function __construct(public string $sql, public bool $allowPartial = false, public LogQuery $filter = new LogQuery())
    {
        if (trim($sql) === '' || strlen($sql) > 65536) {
            throw new InvalidInputException('SQL must contain 1–65536 bytes.');
        }
    }
}
