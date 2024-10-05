<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Country;

use function is_null;

/**
 * Provides a getter for the country.
 */
trait CountryTrait
{
    /**
     * The country
     *
     * @var Country
     */
    private Country $country;

    /**
     * Returns the country.
     *
     * @return Country
     */
    public function getCountry(): Country
    {
        return $this->country;
    }

    /**
     * Sets the country by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setCountryFromArray(array $input): void
    {
        $this->country = is_null($country = ArrayAccess::getString($input, 'country'))
            ? new Country()
            : new Country($country);
    }
}
