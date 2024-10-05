<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A track number as displayed on a release
 */
class TrackDisplayNumber implements Value
{
    /**
     * The displayed track number
     *
     * @var null|string
     */
    private $trackNumber;

    /**
     * Constructs a barcode.
     *
     * @param null|string $trackNumber The displayed track number
     */
    public function __construct(?string $trackNumber = null)
    {
        $this->trackNumber = $trackNumber;
    }

    /**
     * Returns the track number as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return isset($this->trackNumber) ? (string) $this->trackNumber : '';
    }
}
