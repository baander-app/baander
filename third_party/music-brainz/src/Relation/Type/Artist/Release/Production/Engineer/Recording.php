<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Production\Engineer;

use MusicBrainz\Relation\Type\Artist\Release\Production\Engineer;
use MusicBrainz\Value\Name;

/**
 * This describes an engineer responsible for committing the performance to tape or another recording medium. This can be as complex as setting up the microphones, amplifiers, and recording devices, or as simple as pressing the 'record' button on a 4-track or a digital audio workstation.
 *
 * @link https://musicbrainz.org/relationship/023a6c6d-80af-4f88-ae69-f5f6213f9bf4
 */
class Recording extends Engineer
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('recording');
    }
}
