<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of events
 */
class EventList extends ValueList
{
    /**
     * Constructs a list of events.
     *
     * @param array $events An array containing information about the events
     */
    public function __construct(array $events = [])
    {
        parent::__construct(
            array_map(
                function ($event) {
                    return new Event($event);
                },
                $events
            )
        );
    }

    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    public static function getType(): string
    {
        return Event::class;
    }
}
