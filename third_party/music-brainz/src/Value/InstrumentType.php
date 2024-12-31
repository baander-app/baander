<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

use function in_array;
use function is_null;

/**
 * An instrument type
 */
class InstrumentType implements Value
{
    /**
     * An electronic instrument
     *
     * @var string
     */
    public const ELECTRONIC_INSTRUMENT = 'Electronic instrument';

    /**
     * A percussion instrument
     *
     * @var string
     */
    public const PERCUSSION_INSTRUMENT = 'Percussion instrument';

    /**
     * A string instrument
     *
     * @var string
     */
    public const STRING_INSTRUMENT = 'String instrument';

    /**
     * A wind instrument
     *
     * @var string
     */
    public const WIND_INSTRUMENT = 'Wind instrument';

    /**
     * Another instrument
     *
     * @var string
     */
    public const OTHER_INSTRUMENT = 'Other instrument';

    /**
     * An undefined instrument type
     *
     * @var string
     */
    public const UNDEFINED = '';

    /**
     * A list of valid instrument types
     *
     * @var string[]
     */
    private const INSTRUMENT_TYPES = [
        self::ELECTRONIC_INSTRUMENT,
        self::PERCUSSION_INSTRUMENT,
        self::STRING_INSTRUMENT,
        self::WIND_INSTRUMENT,
        self::OTHER_INSTRUMENT,
        self::UNDEFINED,
    ];

    /**
     * The instrument type code
     *
     * @var string
     */
    private $instrumentTypeCode;

    /**
     * The MusicBrainz Identifier (MBID) of the instument type
     *
     * @var MBID
     */
    private $mbid;

    /**
     * Constructs an instrument type.
     *
     * @param string $instrumentTypeCode The instrument type code
     * @param MBID   $mbid               The MusicBrainz Identifier (MBID) of the instument type
     */
    public function __construct(?string $instrumentTypeCode = self::UNDEFINED, MBID $mbid = null)
    {
        if (is_null($instrumentTypeCode)) {
            $this->instrumentTypeCode = self::UNDEFINED;
        } else {
            $this->instrumentTypeCode = in_array($instrumentTypeCode, self::INSTRUMENT_TYPES)
                ? $instrumentTypeCode
                : self::UNDEFINED;
        }

        $this->mbid = $mbid ?: new MBID();
    }

    /**
     * Returns the MusicBrainz Identifier (MBID) of the instument type.
     *
     * @return MBID
     */
    public function getMbid(): MBID
    {
        return $this->mbid;
    }

    /**
     * Returns the instrument type code.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->instrumentTypeCode;
    }
}
