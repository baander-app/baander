<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of works
 */
class WorkList extends ValueList
{
    /**
     * Constructs a list of works.
     *
     * @param array $works Information about the works
     */
    public function __construct(array $works = [])
    {
        parent::__construct(
            array_map(
                function ($work) {
                    return new Work($work);
                },
                $works
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
        return Work::class;
    }
}
