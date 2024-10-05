<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\EventName;

use function is_null;

/**
 * Provides a getter for an event name.
 */
trait EventNameTrait
{
    /**
     * The event name
     *
     * @var EventName
     */
    public EventName $eventName;

    /**
     * Returns the event name.
     *
     * @return EventName
     */
    public function getEventName(): EventName
    {
        return $this->eventName;
    }

    /**
     * Sets the event name by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setEventNameFromArray(array $input): void
    {
        $this->eventName = is_null($eventName = ArrayAccess::getString($input, 'name'))
            ? new EventName()
            : new EventName($eventName);
    }
}
