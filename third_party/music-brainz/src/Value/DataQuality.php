<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

use function in_array;

/**
 * The data quality
 * Data quality indicates how good the data for a release is. It is not a mark of how good or bad the music itself is.
 *
 * @link https://musicbrainz.org/doc/Release
 */
class DataQuality implements Value
{
    /**
     * Low data quality
     * The release needs serious fixes, or its existence is hard to prove (but it's not clearly fake).
     *
     * @var string
     */
    public const LOW = 'low';

    /**
     * Normal data quality
     * This is the default setting
     *
     * @var string
     */
    public const NORMAL = 'normal';

    /**
     * High data quality
     * All available data has been added, if possible including cover art with liner info that proves it.
     *
     * @var string
     */
    public const HIGH = 'high';

    /**
     * Undefined quality
     *
     * @var string
     */
    public const UNDEFINED = 'unknown';

    /**
     * A list of valid quality codes.
     *
     * @var string[]
     */
    public const QUALITY_CODES = [
        self::LOW,
        self::NORMAL,
        self::HIGH,
    ];

    /**
     * The quality code
     *
     * @var string
     */
    private $qualityCode;

    /**
     * Constructs a quality.
     *
     * @param string $qualityCode The quality code
     */
    public function __construct(string $qualityCode = self::UNDEFINED)
    {
        $this->qualityCode = (in_array($qualityCode, self::QUALITY_CODES)) ? $qualityCode : self::UNDEFINED;
    }

    /**
     * Returns the relevance quality as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (null === $this->qualityCode)
            ? ''
            : $this->qualityCode;
    }
}
