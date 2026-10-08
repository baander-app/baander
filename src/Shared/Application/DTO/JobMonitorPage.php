<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

/** One page of a job monitor listing. */
final readonly class JobMonitorPage
{
    /** @param list<JobMonitorRecord> $items */
    public function __construct(
        public array $items,
        /** Continues the listing; null on the last page. */
        public ?string $nextCursor,
        public bool $hasNextPage,
        public int $perPage,
    ) {
    }
}
