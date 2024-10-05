<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

use function in_array;

/**
 * An area type
 *
 * @link https://musicbrainz.org/doc/Area
 */
class AreaType implements Value
{
    /**
     * Country
     * Country is used for areas included (or previously included) in ISO 3166-1, e.g. United States.
     *
     * @var string
     */
    public const COUNTRY = 'Country';

    /**
     * Subdivision
     * Subdivision is used for the main administrative divisions of a country, e.g. California, Ontario, Okinawa. These
     * are considered when displaying the parent areas for a given area.
     *
     * @var string
     */
    public const SUBDIVISION = 'Subdivision';

    /**
     * County
     * County is used for smaller administrative divisions of a country which are not the main administrative
     * divisions but are also not municipalities, e.g. counties in the USA. These are not considered when displaying the
     * parent areas for a given area.
     *
     * @var string
     */
    public const COUNTY = 'County';

    /**
     * Municipality
     * Municipality is used for small administrative divisions which, for urban municipalities, often contain a
     * single city and a few surrounding villages. Rural municipalities typically group several villages together.
     *
     * @var string
     */
    public const MUNICIPALITY = 'Municipality';

    /**
     * City
     * City is used for settlements of any size, including towns and villages.
     *
     * @var string
     */
    public const CITY = 'City';

    /**
     * District
     * District is used for a division of a large city, e.g. Queens.
     *
     * @var string
     */
    public const DISTRICT = 'District';

    /**
     * Island
     * Island is used for islands and atolls which don't form subdivisions of their own, e.g. Skye. These are not
     * considered when displaying the parent areas for a given area.
     *
     * @var string
     */
    public const ISLAND = 'Island';

    /**
     * Undefined area type
     *
     * @var string
     */
    public const UNDEFINED = '';

    /**
     * A list a valid area type codes
     *
     * @var string[]
     */
    public const TYPE_CODES = [
        self::COUNTRY,
        self::SUBDIVISION,
        self::COUNTY,
        self::MUNICIPALITY,
        self::CITY,
        self::DISTRICT,
    ];

    /**
     * The area type code
     *
     * @var string
     */
    private $typeCode;

    /**
     * Constructs an area type.
     *
     * @param string $typeCode An area type code
     */
    public function __construct(string $typeCode = self::UNDEFINED)
    {
        $this->typeCode = (in_array($typeCode, self::TYPE_CODES)) ? $typeCode : self::UNDEFINED;
    }

    /**
     * Returns the area type code.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->typeCode;
    }
}
