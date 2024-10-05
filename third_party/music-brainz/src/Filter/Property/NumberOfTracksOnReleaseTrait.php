<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\TrackNumber;

trait NumberOfTracksOnReleaseTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the number of tracks on release as a whole.
     *
     * @return string
     */
    public static function numberOfTracksOnRelease(): string
    {
        return 'tracks';
    }

    /**
     * Adds the number of tracks on release as a whole.
     *
     * @param TrackNumber $numberOfTracksOnRelease The number of tracks on release as a whole
     *
     * @return Term
     */
    public function addNumberOfTracksOnRelease(TrackNumber $numberOfTracksOnRelease): Term
    {
        return $this->addTerm((string) $numberOfTracksOnRelease, self::numberOfTracksOnRelease());
    }
}
