<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\VideoMatch;

use App\Catalog\Domain\Model\VideoProviderPause;

final readonly class VideoMatchReviewPage
{
    /** @param list<VideoMatchReview> $items
     * @param list<VideoProviderPause> $pausedProviders
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public bool $hasNextPage,
        public int $perPage,
        public array $pausedProviders,
    ) {}
}
