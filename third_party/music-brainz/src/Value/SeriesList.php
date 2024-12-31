<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of series
 */
class SeriesList extends ValueList
{
    /**
     * Constructs a list of series.
     *
     * @param array $series An array containing information about the series
     */
    public function __construct(array $series = [])
    {
        parent::__construct(
            array_map(
                function ($series) {
                    return new Series($series);
                },
                $series
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
        return Series::class;
    }
}
