<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A track
 */
class Track implements Value
{
    use Property\MBIDTrait;
    use Property\TitleTrait;
    use Property\LengthTrait;
    use Property\ArtistCreditsTrait;
    use Property\TrackDisplayNumberTrait;

    /**
     * Constructs a track.
     *
     * @param array $track Information about the track
     */
    public function __construct(array $track = [])
    {
        $this->setMbidFromArray($track);
        $this->setTitleFromArray($track);
        $this->setLengthFromArray($track);
        $this->setArtistCreditsFromArray($track);
        $this->setTrackDisplayNumberFromArray($track);
    }

    /**
     * Returns the track as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->title;
    }
}
