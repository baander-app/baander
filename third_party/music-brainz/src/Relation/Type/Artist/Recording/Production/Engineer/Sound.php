<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Production\Engineer;

use MusicBrainz\Relation\Type\Artist\Recording\Production\Engineer;
use MusicBrainz\Value\Name;

/**
 * This describes an engineer responsible for ensuring that the sounds that the artists make reach the microphones sounding pleasant, without unwanted resonance or noise. Sometimes known as acoustical engineering.
 *
 * @link https://musicbrainz.org/relationship/0cd6aa63-c297-42ed-8725-c16d31913a98
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
