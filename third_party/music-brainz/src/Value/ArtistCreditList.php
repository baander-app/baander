<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of artist credits
 */
class ArtistCreditList extends ValueList
{
    /**
     * Constructs a list of artist credits.
     *
     * @param array $artistCredits An array of information about the artist credits
     */
    public function __construct(array $artistCredits = [])
    {
        parent::__construct(
            array_map(
                function ($artistCredit) {
                    return new ArtistCredit($artistCredit);
                },
                $artistCredits
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
        return ArtistCredit::class;
    }
}
