<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of release events
 */
class ReleaseEventList extends ValueList
{
    /**
     * Constructs a list of release events.
     *
     * @param array $releaseEvents Information about the releaseEvents
     */
    public function __construct(array $releaseEvents = [])
    {
        parent::__construct(
            array_map(
                function ($releaseEvent) {
                    return new ReleaseEvent($releaseEvent);
                },
                $releaseEvents
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
        return Release::class;
    }
}
