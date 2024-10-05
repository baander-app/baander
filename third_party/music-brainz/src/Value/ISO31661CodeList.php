<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of ISO 3166-1 codes
 */
class ISO31661CodeList extends ValueList
{
    /**
     * Constructs a list of ISO 3166-1 codes.
     *
     * @param array $codes Information about the ISO 3166-1 codes
     */
    public function __construct(array $codes = [])
    {
        parent::__construct(
            array_map(
                function ($code) {
                    return new ISO31661Code($code);
                },
                $codes
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
        return ISO31661Code::class;
    }
}
