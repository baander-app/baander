<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

/** A message handled in the calling process and recorded in the job monitor. */
final readonly class InlineJobRun
{
    public function __construct(
        /** The job monitor's ID for the run. */
        public string $jobId,
        /** What the message's handler returned. */
        public mixed $result,
    ) {
    }
}
