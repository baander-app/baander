<?php

declare(strict_types=1);

namespace App\Scheduler\Interface\Resource;

use App\Scheduler\Domain\Model\ScheduledJob;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ScheduledJobResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid', description: 'Job UUID'),
        new OA\Property(property: 'name', type: 'string', description: 'Job name'),
        new OA\Property(property: 'expression', type: 'string', description: 'Cron expression'),
        new OA\Property(property: 'jobType', type: 'string', enum: ['messenger', 'console'], description: 'Job type'),
        new OA\Property(property: 'command', type: 'string', description: 'Command to execute'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'paused', 'disabled'], description: 'Job status'),
        new OA\Property(property: 'description', type: 'string', nullable: true, description: 'Job description'),
        new OA\Property(property: 'parameters', type: 'object', description: 'Job parameters'),
        new OA\Property(property: 'lastRunAt', type: 'string', format: 'date-time', nullable: true, description: 'Last run timestamp'),
        new OA\Property(property: 'nextRunAt', type: 'string', format: 'date-time', nullable: true, description: 'Next scheduled run timestamp'),
        new OA\Property(property: 'lastResult', type: 'string', nullable: true, description: 'Last execution result'),
        new OA\Property(property: 'runCount', type: 'integer', description: 'Total number of runs'),
        new OA\Property(property: 'lastFailureAt', type: 'string', format: 'date-time', nullable: true, description: 'Last failure timestamp'),
        new OA\Property(property: 'lastError', type: 'string', nullable: true, description: 'Last error message'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', description: 'Creation timestamp'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', description: 'Last update timestamp'),
    ],
)]
final class ScheduledJobResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof ScheduledJob);

        return [
            'id' => $source->getId()->toString(),
            'name' => $source->getName(),
            'expression' => $source->getExpression(),
            'jobType' => $source->getJobType()->value,
            'command' => $source->getCommand(),
            'status' => $source->getStatus()->value,
            'description' => $source->getDescription(),
            'parameters' => $source->getParameters(),
            'lastRunAt' => $source->getLastRunAt()?->format(\DateTimeInterface::ATOM),
            'nextRunAt' => $source->getNextRunAt()?->format(\DateTimeInterface::ATOM),
            'lastResult' => $source->getLastResult(),
            'runCount' => $source->getRunCount(),
            'lastFailureAt' => $source->getLastFailureAt()?->format(\DateTimeInterface::ATOM),
            'lastError' => $source->getLastError(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $source->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
