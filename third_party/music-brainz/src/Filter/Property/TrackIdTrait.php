<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\MBID;

trait TrackIdTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the MusicBrainz ID (MBID) of a track.
     *
     * @return string
     */
    public static function trackId(): string
    {
        return 'tid';
    }

    /**
     * Adds the MusicBrainz ID (MBID) of a track.
     *
     * @param MBID $trackId The MusicBrainz ID (MBID) of a track
     *
     * @return Term
     */
    public function addTrackId(MBID $trackId): Term
    {
        return $this->addTerm((string) $trackId, self::trackId());
    }
}
