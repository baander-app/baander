<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A number of rating votes
 *
 * @link https://musicbrainz.org/doc/Rating_System
 */
class RatingVotes implements Value
{
    /**
     * The number of votes
     *
     * @var int
     */
    private $votes;

    /**
     * Constructs a number of rating votes.
     *
     * @param int $ratingVotes A number of rating votes
     */
    public function __construct(int $ratingVotes = 0)
    {
        $this->votes = ($ratingVotes > 0)
            ? $ratingVotes
            : 0;
    }

    /**
     * Returns the rating as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->votes;
    }
}
