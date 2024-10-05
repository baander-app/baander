<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A rating
 *
 * @link https://musicbrainz.org/doc/Rating_System
 */
class Rating implements Value
{
    use Property\RatingValueTrait;
    use Property\RatingVotesTrait;

    /**
     * Constructs a rating.
     *
     * @param array $rating Information about the rating
     */
    public function __construct(array $rating = [])
    {
        $this->setRatingValueFromArray($rating);
        $this->setRatingVotesFromArray($rating);
    }

    /**
     * Returns the rating as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->getRatingValue();
    }
}
