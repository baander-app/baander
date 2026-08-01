<?php

declare(strict_types=1);

namespace App\Shared\Application;

interface CancellableJobInterface
{
    /**
     * Check if the job has been cancelled via the cooperative Redis flag.
     *
     * Call this at checkpoints within long-running handlers.
     * Throws JobCancelledException if the job has been cancelled.
     *
     * @throws JobCancelledException
     */
    public function checkCancellation(string $jobId): void;
}
