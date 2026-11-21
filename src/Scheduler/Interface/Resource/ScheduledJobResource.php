<?php

declare(strict_types=1);

namespace App\Scheduler\Interface\Resource;

use App\Scheduler\Domain\Model\ScheduledJob;
use App\Shared\Application\PublicDiagnosticText;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(description: 'Public job identity', type: 'string'),
        'name' => new OA\Schema(description: 'Job name', type: 'string'),
        'expression' => new OA\Schema(description: 'Cron expression', type: 'string'),
        'jobType' => new OA\Schema(
            description: 'Job type',
            type: 'string',
            enum: ['messenger', 'console'],
        ),
        'command' => new OA\Schema(description: 'Command to execute', type: 'string'),
        'status' => new OA\Schema(
            description: 'Job status',
            type: 'string',
            enum: ['active', 'paused', 'disabled'],
        ),
        'description' => new OA\Schema(
            description: 'Job description',
            type: 'string',
            nullable: true,
        ),
        'parameters' => new OA\Schema(description: 'Job parameters', type: 'object'),
        'lastRunAt' => new OA\Schema(
            description: 'Last run timestamp',
            type: 'string',
            format: 'date-time',
            nullable: true,
        ),
        'nextRunAt' => new OA\Schema(
            description: 'Next scheduled run timestamp',
            type: 'string',
            format: 'date-time',
            nullable: true,
        ),
        'lastResult' => new OA\Schema(
            description: 'Last execution result',
            type: 'string',
            nullable: true,
        ),
        'runCount' => new OA\Schema(description: 'Total number of runs', type: 'integer'),
        'lastFailureAt' => new OA\Schema(
            description: 'Last failure timestamp',
            type: 'string',
            format: 'date-time',
            nullable: true,
        ),
        'lastError' => new OA\Schema(
            description: 'Last error message',
            type: 'string',
            nullable: true,
        ),
        'createdAt' => new OA\Schema(
            description: 'Creation timestamp',
            type: 'string',
            format: 'date-time',
        ),
        'updatedAt' => new OA\Schema(
            description: 'Last update timestamp',
            type: 'string',
            format: 'date-time',
        ),
    ],
    required: [
        'publicId',
        'name',
        'expression',
        'jobType',
        'command',
        'status',
        'parameters',
        'runCount',
        'createdAt',
        'updatedAt',
    ],
    component: 'ScheduledJobResource',
)]
final class ScheduledJobResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof ScheduledJob);

        return [
            'publicId' => $source->getPublicId()->toString(),
            'name' => $source->getName(),
            'expression' => $source->getExpression(),
            'jobType' => $source->getJobType()->value,
            'command' => $source->getCommand(),
            'status' => $source->getStatus()->value,
            'description' => $source->getDescription(),
            'parameters' => $source->getParameters(),
            'lastRunAt' => $source->getLastRunAt()?->format(\DateTimeInterface::ATOM),
            'nextRunAt' => $source->getNextRunAt()?->format(\DateTimeInterface::ATOM),
            'lastResult' => $source->getLastResult() === null ? null : PublicDiagnosticText::redact($source->getLastResult()),
            'runCount' => $source->getRunCount(),
            'lastFailureAt' => $source->getLastFailureAt()?->format(\DateTimeInterface::ATOM),
            'lastError' => $source->getLastError() === null ? null : PublicDiagnosticText::redact($source->getLastError()),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $source->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
