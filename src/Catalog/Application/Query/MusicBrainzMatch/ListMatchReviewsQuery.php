<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

/**
 * One page of the albums the matcher left for an admin, the most recently checked first:
 * GET /api/admin/metadata/match/reviews and `app:metadata:match:reviews`.
 */
final readonly class ListMatchReviewsQuery
{
    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 100;

    public function __construct(
        /** 1 to MAX_LIMIT. */
        public int $limit = self::DEFAULT_LIMIT,
        /** The next cursor of the previous page, or null for the first page. */
        public ?string $cursor = null,
    ) {
    }
}
