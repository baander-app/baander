<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

/** The job monitor at a glance: how many jobs have each status, and the jobs running now. */
final readonly class JobMonitorOverview
{
    /**
     * @param array<string, int>     $counts  job counts keyed by status; a status without jobs is absent
     * @param list<JobMonitorRecord> $running running jobs, longest-running first
     */
    public function __construct(
        public array $counts,
        public array $running,
    ) {
    }
}
