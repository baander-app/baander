<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

use function strlen;

/**
 * A country
 *
 * @link https://wiki.musicbrainz.org/Release_Country
 */
class Country implements Value
{
    /**
     * The country code
     *
     * @var string
     */
    private $countryCode;

    /**
     * Constructs a country.
     *
     * @param string $countryCode A country code
     */
    public function __construct(string $countryCode = '')
    {
        $this->countryCode = (strlen($countryCode) === 2)
            ? strtoupper($countryCode)
            : '';
    }

    /**
     * Returns the country code.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->countryCode;
    }
}
