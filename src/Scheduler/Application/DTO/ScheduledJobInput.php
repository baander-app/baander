<?php

declare(strict_types=1);

namespace App\Scheduler\Application\DTO;

final readonly class ScheduledJobInput
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        public string $name,
        public string $expression,
        public string $jobType,
        public string $command,
        public ?string $description = null,
        public array $parameters = [],
    ) {
    }
}
