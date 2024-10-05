<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Value\ReleaseEvent;

/**
 * Provides a getter for a release event.
 */
trait ReleaseEventTrait
{
    /**
     * The release event
     *
     * @var ReleaseEvent
     */
    private ReleaseEvent $releaseEvent;

    /**
     * Returns the release event.
     *
     * @return ReleaseEvent
     */
    public function getReleaseEvent(): ReleaseEvent
    {
        return $this->releaseEvent;
    }
}
