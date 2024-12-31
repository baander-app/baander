<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of ISNI codes
 *
 * @see ISNI
 */
class ISNIList extends ValueList
{
    /**
     * Constructs a list of ISNI codes.
     *
     * @param array $isniCodes An array of ISNI code strings
     */
    public function __construct(array $isniCodes = [])
    {
        parent::__construct(
            array_map(
                function ($isniCode) {
                    return new ISNI($isniCode);
                },
                $isniCodes
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
        return ISNI::class;
    }
}
