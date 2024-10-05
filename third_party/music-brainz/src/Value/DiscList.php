<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of discs
 */
class DiscList extends ValueList
{
    /**
     * Constructs a list of discs.
     *
     * @param array $discs Information about the discs
     */
    public function __construct(array $discs = [])
    {
        parent::__construct(
            array_map(
                function ($disc) {
                    return new Disc($disc);
                },
                $discs
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
        return Disc::class;
    }
}
