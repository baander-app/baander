<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Production\Misc;

use MusicBrainz\Relation\Type\Artist\Recording\Production\Misc;
use MusicBrainz\Value\Name;

/**
 * This indicates that a person or firm provided legal representation for the recording.
 *
 * @link https://musicbrainz.org/relationship/75e37b65-7b50-4080-bf04-8c59c66b5f65
 */
class LegalRepresentation extends Misc
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('legal representation');
    }
}
