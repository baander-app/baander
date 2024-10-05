<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\Country;

trait CountryTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the country.
     *
     * @return string
     */
    public static function country(): string
    {
        return 'country';
    }

    /**
     * Adds a country.
     *
     * @param Country $country A country
     *
     * @return Term
     */
    public function addCountry(Country $country): Term
    {
        return $this->addTerm((string) $country, self::country());
    }
}
