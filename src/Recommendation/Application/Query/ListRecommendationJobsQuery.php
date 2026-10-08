<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Query;

/** The most recent recommendation jobs, newest first, optionally with one status. */
final readonly class ListRecommendationJobsQuery
{
    public const int DEFAULT_LIMIT = 20;
    public const int MAX_LIMIT = 100;

    /**
     * @param int         $limit  clamped to 1-100, as the admin page has always been served
     * @param string|null $status pending, in_progress, completed, failed or cancelled
     */
    public function __construct(
        public int $limit = self::DEFAULT_LIMIT,
        public ?string $status = null,
    ) {
    }
}
