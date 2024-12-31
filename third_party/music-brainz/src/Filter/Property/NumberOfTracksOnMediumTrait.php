<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\TrackNumber;

trait NumberOfTracksOnMediumTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the number of tracks in the medium.
     *
     * @return string
     */
    public static function numberOfTracksOnMedium(): string
    {
        return 'tracksmedium';
    }

    /**
     * Adds the number of tracks in the medium on release.
     *
     * @param TrackNumber $numberOfTracksOnMedium The number of tracks in the medium on release
     *
     * @return Term
     */
    public function addNumberOfTracksOnMedium(TrackNumber $numberOfTracksOnMedium): Term
    {
        return $this->addTerm((string) $numberOfTracksOnMedium, self::numberOfTracksOnMedium());
    }
}
