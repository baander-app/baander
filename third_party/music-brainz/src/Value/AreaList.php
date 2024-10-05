<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of areas
 */
class AreaList extends ValueList
{
    /**
     * Constructs a list of areas.
     *
     * @param array $areas An array of area arrays
     */
    public function __construct(array $areas = [])
    {
        parent::__construct(
            array_map(
                function ($area) {
                    return new Area($area);
                },
                $areas
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
        return Area::class;
    }
}
