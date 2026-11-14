<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

use App\Shared\Application\Exception\InvalidInputException;

/**
 * Reads the albums that need a MusicBrainz match review. Reads committed rows, takes no lock and
 * changes nothing.
 */
interface MatchReviewReadInterface
{
    /**
     * The albums marked as needing a review, ordered by the time the matcher checked them, the most
     * recent first, then by album. A cursor continues after the album it was issued for, so a later
     * page repeats no album of an earlier one even when reviews change in between.
     *
     * @param ?string $cursor the next cursor of an earlier page, or null for the first page
     * @param int     $limit  the page size, at least 1
     *
     * @throws InvalidInputException when the cursor is not one this list issued
     */
    public function page(?string $cursor, int $limit): MatchReviewPage;
}
