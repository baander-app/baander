<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\TrackNumber;

trait NumberOfTracksOnCdStub
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the number of tracks on a CD stub.
     *
     * @return string
     */
    public static function numberOfTracksOnCdStub(): string
    {
        return 'tracks';
    }

    /**
     * Adds the number of tracks on the CD stub.
     *
     * @param TrackNumber $numberOfTracksOnMedium The number of tracks on the CD stub
     *
     * @return Term
     */
    public function addNumberOfTracksOnCdStub(TrackNumber $numberOfTracksOnMedium): Term
    {
        return $this->addTerm((string) $numberOfTracksOnMedium, self::numberOfTracksOnCdStub());
    }
}
