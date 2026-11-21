<?php

declare(strict_types=1);

namespace App\Scheduler\Interface\Request;

use App\Scheduler\Interface\Validator\CronExpression;
use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'name' => new OA\Schema(type: 'string', maxLength: 255),
        'expression' => new OA\Schema(type: 'string'),
        'jobType' => new OA\Schema(
            type: 'string',
            enum: ['messenger', 'console'],
        ),
        'command' => new OA\Schema(type: 'string'),
        'description' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
        'parameters' => new OA\Schema(
            type: 'object',
            additionalProperties: new OA\Schema(nullable: true),
            default: new \stdClass(),
        ),
    ],
    required: ['name', 'expression', 'jobType', 'command'],
    component: 'UpdateScheduledJobRequest',
)]
final readonly class UpdateScheduledJobRequest
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $name,

        #[Assert\NotBlank]
        #[CronExpression]
        public string $expression,

        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['messenger', 'console'])]
        public string $jobType,

        #[Assert\NotBlank]
        public string $command,

        public ?string $description = null,

        public array $parameters = [],
    ) {
    }
}
