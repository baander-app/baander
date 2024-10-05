<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of IPI codes
 *
 * @see https://musicbrainz.org/doc/IPI
 */
class IPIList extends ValueList
{
    /**
     * Constructs a list of IPI codes.
     *
     * @param array $ipiCodes An array of IPI code strings
     */
    public function __construct(array $ipiCodes = [])
    {
        parent::__construct(
            array_map(
                function ($ipiCode) {
                    return new IPI($ipiCode);
                },
                $ipiCodes
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
        return IPI::class;
    }
}
