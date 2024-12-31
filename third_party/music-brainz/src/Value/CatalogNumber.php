<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A catalog number
 * This is a number assigned to the release by the label which can often be found on the spine or near the barcode.
 * There may be more than one, especially when multiple labels are involved. This is not the ASIN — there is a
 * relationship for that — nor the label code.
 *
 * @see https://musicbrainz.org/doc/Release#Catalogue_number
 */
class CatalogNumber implements Value
{
    /**
     * A catalog number
     *
     * @var string
     */
    private $catalogNumber;

    /**
     * Constructs a catalog number.
     *
     * @param string $catalogNumber A catalog number
     */
    public function __construct(string $catalogNumber = '')
    {
        $this->catalogNumber = $catalogNumber;
    }

    /**
     * Returns the catalog number as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->catalogNumber;
    }
}
