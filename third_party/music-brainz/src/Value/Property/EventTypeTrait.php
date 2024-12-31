<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\EventType;

use function is_null;

/**
 * Provides a getter for the event type.
 */
trait EventTypeTrait
{
    /**
     * The event type
     *
     * @var EventType
     */
    private EventType $eventType;

    /**
     * Returns the event type.
     *
     * @return EventType
     */
    public function getEventType(): EventType
    {
        return $this->eventType;
    }

    /**
     * Sets the event type by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setEventTypeFromArray(array $input): void
    {
        $this->eventType = is_null($eventType = ArrayAccess::getString($input, 'type'))
            ? new EventType()
            : new EventType($eventType);
    }
}
