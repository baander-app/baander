<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of places
 */
class PlaceList extends ValueList
{
    /**
     * Constructs a list of places.
     *
     * @param array $places An array containing information about the places
     */
    public function __construct(array $places = [])
    {
        parent::__construct(
            array_map(
                function ($place) {
                    return new Place($place);
                },
                $places
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
        return Place::class;
    }
}
