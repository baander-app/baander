<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A release event
 */
class ReleaseEvent implements Value
{
    use Property\AreaTrait;
    use Property\DateTrait;

    /**
     * Constructs a release event.
     *
     * @param array $releaseEvent Array of information about the release event
     */
    public function __construct(array $releaseEvent = [])
    {
        $this->setAreaFromArray($releaseEvent);
        $this->setDateFromArray($releaseEvent);
    }

    /**
     * Returns the release event as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        if (empty((string) $this->getDate())) {
            $releaseEvent = (empty((string) $this->getArea()))
                ? ''
                : (string) $this->getArea();
        } else {
            $releaseEvent = $this->getDate();
            $releaseEvent .= (empty((string) $this->getArea()))
                ? ''
                : ' (' . $this->getArea() . ')';
        }

        return $releaseEvent;
    }
}
