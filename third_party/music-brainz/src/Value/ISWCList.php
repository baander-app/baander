<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of International Standard Musical Work Codes (ISWC)
 */
class ISWCList extends ValueList
{
    /**
     * Constructs a list of International Standard Musical Work Code (ISWC).
     *
     * @param array $codes A list of International Standard Musical Work Codes (ISWC)
     */
    public function __construct(array $codes = [])
    {
        parent::__construct(
            array_map(
                function ($code) {
                    return new ISWC($code);
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
        return ISWC::class;
    }
}
