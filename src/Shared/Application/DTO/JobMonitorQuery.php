<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

/**
 * Filters, order and page of a job monitor listing. An unknown sort or direction falls
 * back to the default, and the limit is clamped to 1-200.
 */
final readonly class JobMonitorQuery
{
    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 200;

    public function __construct(
        /** queued, running, finished, failed or cancelled */
        public ?string $status = null,
        /** Part of the job type name. */
        public ?string $name = null,
        /** The exact queue name. */
        public ?string $queue = null,
        /** createdAt, startedAt, finishedAt or duration */
        public string $sort = 'createdAt',
        /** asc or desc */
        public string $direction = 'desc',
        public int $limit = self::DEFAULT_LIMIT,
        /** The previous page's next cursor; an unreadable cursor starts at the first page. */
        public ?string $cursor = null,
    ) {
    }
}
