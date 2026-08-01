<?php

declare(strict_types=1);

namespace App\Scheduler\Application\Command;

final readonly class ExecuteScheduledJobCommand
{
    public function __construct(
        public string $jobId,
        public string $jobType,
        public string $command,
        public array $parameters,
    ) {
    }
}
