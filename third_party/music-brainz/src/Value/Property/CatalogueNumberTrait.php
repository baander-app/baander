<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\CatalogNumber;

use function is_null;

/**
 * Provides a getter for the catalog number.
 */
trait CatalogueNumberTrait
{
    /**
     * A catalog number
     *
     * @var CatalogNumber
     */
    private CatalogNumber $catalogNumber;

    /**
     * Returns the catalogue number.
     *
     * @return CatalogNumber
     */
    public function getCatalogueNumber(): CatalogNumber
    {
        return $this->catalogNumber;
    }

    /**
     * Sets the catalog number by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setCatalogueNumberFromArray(array $input): void
    {
        $this->catalogNumber = is_null($catalogNumber = ArrayAccess::getString($input, 'catalog-number'))
            ? new CatalogNumber()
            : new CatalogNumber($catalogNumber);
    }
}
