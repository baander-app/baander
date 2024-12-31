<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A life span.
 *
 * @link https://musicbrainz.org/doc/Artist
 */
class LifeSpan implements Value
{
    use Property\BeginDateTrait;
    use Property\EndDateTrait;
    use Property\EndedTrait;

    /**
     * Constructs a life span.
     *
     * @param array $lifeSpan An array of information about a life span
     */
    public function __construct(array $lifeSpan = [])
    {
        $this->setBeginDateFromArray($lifeSpan);
        $this->setEndDateFromArray($lifeSpan);
        $this->setEndedFromArray($lifeSpan);
    }

    /**
     * Returns the life span as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return ($this->getEnded()->isEnded())
            ? $this->getBeginDate() . ' – ' . $this->getEndDate()
            : (string) $this->getBeginDate();
    }
}
