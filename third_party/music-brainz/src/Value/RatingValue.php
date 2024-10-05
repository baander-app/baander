<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A rating value
 *
 * @link https://musicbrainz.org/doc/Rating_System
 */
class RatingValue implements Value
{
    /**
     * A value between 0 and 5
     *
     * @var null|float
     */
    private $value;

    /**
     * Constructs a rating value.
     *
     * @param float $ratingValue A rating value
     */
    public function __construct(float $ratingValue = null)
    {
        $this->value = ($ratingValue >= 0 && $ratingValue <= 5)
            ? $ratingValue
            : null;
    }

    /**
     * Returns the rating as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->value;
    }
}
