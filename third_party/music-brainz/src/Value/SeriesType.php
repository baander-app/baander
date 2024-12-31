<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

use function in_array;

/**
 * A type of series
 * The type primarily describes what type of entity the series contains.
 *
 * @see https://wiki.musicbrainz.org/Series#Type
 */
class SeriesType implements Value
{
    /**
     * A series of works which form a catalogue of classical compositions
     *
     * @see https://en.wikipedia.org/wiki/Catalogues_of_classical_compositions
     *
     * @var string
     */
    public const CATALOGUE = 'catalogue';

    /**
     * A series of events
     *
     * @var string
     */
    public const EVENT = 'event';

    /**
     * A recurring festival, usually happening annually in the same location
     *
     * @var string
     */
    public const FESTIVAL = 'festival';

    /**
     * A series of recordings
     *
     * @var string
     */
    public const RECORDING = 'recording';

    /**
     * A series of releases
     *
     * @var string
     */
    public const RELEASE = 'release';

    /**
     * A series of release groups
     *
     * @var string
     */
    public const RELEASE_GROUP = 'release group';

    /**
     * A series of performances of the same show at the same venue
     *
     * @var string
     */
    public const RUN = 'run';

    /**
     * A series of related concerts by an artist in different locations
     *
     * @var string
     */
    public const TOUR = 'tour';

    /**
     * A series of works
     *
     * @var string
     */
    public const WORK = 'work';

    /**
     * A list of valid place type codes
     *
     * @var string[]
     */
    public const TYPE_CODES = [
        self::CATALOGUE,
        self::EVENT,
        self::FESTIVAL,
        self::RECORDING,
        self::RELEASE,
        self::RELEASE_GROUP,
        self::RUN,
        self::TOUR,
        self::WORK,
    ];

    /**
     * The code for the type of place
     *
     * @var string
     */
    private $typeCode;

    /**
     * Constructs a type of series.
     *
     * @param string $typeCode A series type code
     */
    public function __construct(string $typeCode = '')
    {
        $this->typeCode = (in_array($typeCode, self::TYPE_CODES))
            ? $typeCode
            : '';
    }

    /**
     * Returns the code for the type of series.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->typeCode;
    }
}
