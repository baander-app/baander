<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Phrase;
use MusicBrainz\Value\CatalogNumber;

trait CatalogNumberTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the catalog number.
     *
     * @return string
     */
    public static function catalogNumber(): string
    {
        return 'catno';
    }

    /**
     * Adds a catalog number.
     *
     * @param CatalogNumber $catalogNumber A catalog number
     *
     * @return Phrase
     */
    public function addCatalogNumber(CatalogNumber $catalogNumber): Phrase
    {
        return $this->addPhrase((string) $catalogNumber, self::catalogNumber());
    }
}
