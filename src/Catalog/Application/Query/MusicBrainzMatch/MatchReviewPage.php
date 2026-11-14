<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

/** The answer to ListMatchReviewsQuery: one page of albums that need a review. */
final readonly class MatchReviewPage
{
    /** @param list<MatchReview> $items the most recently checked first */
    public function __construct(
        public array $items,
        /** Continues the list after the last item; null on the last page. */
        public ?string $nextCursor,
        public bool $hasNextPage,
        public int $perPage,
    ) {
    }

    /**
     * The `data` of GET /api/admin/metadata/match/reviews, which `app:metadata:match:reviews --json` prints.
     *
     * @return array{items: list<array<string, mixed>>, next_cursor: ?string, has_next_page: bool, per_page: int}
     */
    public function toArray(): array
    {
        return [
            'items' => array_map(static fn (MatchReview $review): array => $review->toArray(), $this->items),
            'next_cursor' => $this->nextCursor,
            'has_next_page' => $this->hasNextPage,
            'per_page' => $this->perPage,
        ];
    }
}
