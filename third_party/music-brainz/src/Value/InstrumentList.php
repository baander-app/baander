<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of instruments
 */
class InstrumentList extends ValueList
{
    /**
     * Constructs a list of instruments.
     *
     * @param array $instruments An array instrument arrays
     */
    public function __construct(array $instruments = [])
    {
        parent::__construct(
            array_map(
                function ($instrument) {
                    return new Instrument($instrument);
                },
                $instruments
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
        return Instrument::class;
    }
}
