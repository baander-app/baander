<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Production\Engineer;

use MusicBrainz\Relation\Type\Artist\Recording\Production\Engineer;
use MusicBrainz\Value\Name;

/**
 * This describes an engineer responsible for committing the performance to tape or another recording medium. This can be as complex as setting up the microphones, amplifiers, and recording devices, or as simple as pressing the 'record' button on a 4-track or a digital audio workstation.
 *
 * @link https://musicbrainz.org/relationship/a01ee869-80a8-45ef-9447-c59e91aa7926
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
