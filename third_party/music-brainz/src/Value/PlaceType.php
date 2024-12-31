<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

use function in_array;

/**
 * A type of place
 *
 * @see https://musicbrainz.org/doc/Place
 */
class PlaceType implements Value
{
    /**
     * A place consisting of a large enclosed area with a central event space surrounded by tiered seating for
     * spectators, which can be used for indoor sports, concerts and other entertainment events
     *
     * @var string
     */
    public const INDOOR_ARENA = 'indoor arena';

    /**
     * A place whose main purpose is to host outdoor sport events, typically consisting of a pitch surrounded by a
     * structure for spectators with no roof, or a roof which can be retracted
     *
     * @var string
     */
    public const STADIUM = 'stadium';

    /**
     * A place designed for non-live production of music, typically a recording studio.
     *
     * @var string
     */
    public const STUDIO = 'studio';

    /**
     * A place that has live artistic performances as one of its primary functions, such as a concert hall
     *
     * @var string
     */
    public const VENUE = 'venue';

    /**
     * A place mostly designed and used for religious purposes, like a church, cathedral or synagogue
     *
     * @var string
     */
    public const RELIGIOUS_BUILDING = 'religious building';

    /**
     * Anything which does not fit into the above categories
     *
     * @var string
     */
    public const OTHER = 'other';

    /**
     * A list of valid place type codes
     *
     * @var string[]
     */
    public const TYPE_CODES = [
        self::INDOOR_ARENA,
        self::STADIUM,
        self::STUDIO,
        self::VENUE,
        self::RELIGIOUS_BUILDING,
    ];

    /**
     * The code for the type of place
     *
     * @var string
     */
    private $typeCode;

    /**
     * Constructs a type of place.
     *
     * @param string $typeCode A place type code
     */
    public function __construct(string $typeCode = self::OTHER)
    {
        $this->typeCode = (in_array($typeCode, self::TYPE_CODES))
            ? $typeCode
            : self::OTHER;
    }

    /**
     * Returns the code for the type of place.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->typeCode;
    }
}
