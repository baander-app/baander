<?php

declare(strict_types=1);

namespace App\Catalog\Application\QueryHandler\MusicBrainzMatch;

use App\Catalog\Application\Query\MusicBrainzMatch\ListMatchReviewsQuery;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchReviewPage;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchReviewReadInterface;
use App\Shared\Application\Exception\InvalidInputException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Lists the albums that need a match review for the admin API and `app:metadata:match:reviews`. */
final readonly class ListMatchReviewsHandler
{
    public function __construct(
        private MatchReviewReadInterface $reviews,
    ) {
    }

    /** @throws InvalidInputException when the limit is out of range or the cursor is not one the list issued */
    #[AsMessageHandler]
    public function __invoke(ListMatchReviewsQuery $query): MatchReviewPage
    {
        if ($query->limit < 1 || $query->limit > ListMatchReviewsQuery::MAX_LIMIT) {
            throw new InvalidInputException(sprintf('limit must be between 1 and %d.', ListMatchReviewsQuery::MAX_LIMIT));
        }

        return $this->reviews->page($query->cursor, $query->limit);
    }
}
