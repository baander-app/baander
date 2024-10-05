<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Production\Engineer;

use MusicBrainz\Relation\Type\Artist\Release\Production\Engineer;
use MusicBrainz\Value\Name;

/**
 * This describes an engineer responsible for ensuring that the sounds that the artists make reach the microphones sounding pleasant, without unwanted resonance or noise. Sometimes known as acoustical engineering.
 *
 * @link https://musicbrainz.org/relationship/271306ca-c77f-4fe0-94bc-dd4b87ae0205
 */
class Sound extends Engineer
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('sound');
    }
}
