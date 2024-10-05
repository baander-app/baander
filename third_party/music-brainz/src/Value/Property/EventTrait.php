<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Event;

use function is_null;

/**
 * Provides a getter for the event.
 */
trait EventTrait
{
    /**
     * An event
     *
     * @var Event
     */
    private Event $event;

    /**
     * Returns the event.
     *
     * @return Event
     */
    public function getEvent(): Event
    {
        return $this->event;
    }

    /**
     * Sets the event by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setEventFromArray(array $input): void
    {
        $this->event = is_null($event = ArrayAccess::getArray($input, 'event'))
            ? new Event()
            : new Event($event);
    }
}
