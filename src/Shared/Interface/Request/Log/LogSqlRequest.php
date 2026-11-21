<?php

declare(strict_types=1);

namespace App\Shared\Interface\Request\Log;

use OpenApi\Spec as OA;
use App\Shared\Application\LogViewer\LogSqlQuery;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'sql' => new OA\Schema(type: 'string', maxLength: 65536),
        'allowPartial' => new OA\Schema(type: 'boolean', default: false),
        'filter' => new OA\Schema(ref: LogFilterRequest::class),
    ],
    required: ['sql'],
    component: 'LogSqlRequest',
)]
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
