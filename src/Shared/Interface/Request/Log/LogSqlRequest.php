<?php

declare(strict_types=1);

namespace App\Shared\Interface\Request\Log;

use App\Shared\Application\LogViewer\LogSqlQuery;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class LogSqlRequest
{
    public function __construct(
        #[Assert\NotBlank] #[Assert\Length(max: 65536)] public string $sql,
        public bool $allowPartial = false,
        public LogFilterRequest $filter = new LogFilterRequest(),
    ) {
    }

    public function query(): LogSqlQuery
    {
        return new LogSqlQuery($this->sql, $this->allowPartial, $this->filter->query());
    }
}
