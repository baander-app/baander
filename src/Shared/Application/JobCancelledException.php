<?php

declare(strict_types=1);

namespace App\Shared\Application;

final class JobCancelledException extends \RuntimeException
{
    public static function forJob(string $jobId): self
    {
        return new self(sprintf('Job "%s" has been cancelled.', $jobId));
    }
}
