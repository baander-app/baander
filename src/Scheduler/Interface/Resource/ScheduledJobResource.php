<?php

declare(strict_types=1);

namespace App\Scheduler\Interface\Resource;

use App\Scheduler\Domain\Model\ScheduledJob;
use App\Shared\Application\PublicDiagnosticText;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ScheduledJobResource',
    properties: [
        new OA\Property(property: 'publicId', description: 'Public job identity', type: 'string'),
        new OA\Property(property: 'name', description: 'Job name', type: 'string'),
        new OA\Property(property: 'expression', description: 'Cron expression', type: 'string'),
        new OA\Property(property: 'jobType', description: 'Job type', type: 'string', enum: ['messenger', 'console']),
        new OA\Property(property: 'command', description: 'Command to execute', type: 'string'),
        new OA\Property(property: 'status', description: 'Job status', type: 'string', enum: ['active', 'paused', 'disabled']),
        new OA\Property(property: 'description', description: 'Job description', type: 'string', nullable: true),
        new OA\Property(property: 'parameters', description: 'Job parameters', type: 'object'),
        new OA\Property(property: 'lastRunAt', description: 'Last run timestamp', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'nextRunAt', description: 'Next scheduled run timestamp', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'lastResult', description: 'Last execution result', type: 'string', nullable: true),
        new OA\Property(property: 'runCount', description: 'Total number of runs', type: 'integer'),
        new OA\Property(property: 'lastFailureAt', description: 'Last failure timestamp', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'lastError', description: 'Last error message', type: 'string', nullable: true),
        new OA\Property(property: 'createdAt', description: 'Creation timestamp', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', description: 'Last update timestamp', type: 'string', format: 'date-time'),
    ],
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
