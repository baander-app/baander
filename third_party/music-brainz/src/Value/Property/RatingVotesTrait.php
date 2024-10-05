<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\RatingVotes;

use function is_null;

/**
 * Provides a getter for the number of rating votes.
 */
trait RatingVotesTrait
{
    /**
     * The number of rating votes
     *
     * @var RatingVotes
     */
    private RatingVotes $ratingVotes;

    /**
     * Returns the number of rating votes.
     *
     * @return RatingVotes
     */
    public function getRatingVotes(): RatingVotes
    {
        return $this->ratingVotes;
    }

    /**
     * Sets the rating votes by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setRatingVotesFromArray(array $input): void
    {
        $this->ratingVotes = is_null($ratingVotes = ArrayAccess::getInteger($input, 'votes-count'))
            ? new RatingVotes()
            : new RatingVotes($ratingVotes);
    }
}
