<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Label\BusinessAssociation;

use MusicBrainz\Relation\Type\Label\Label\BusinessAssociation;
use MusicBrainz\Value\Name;

/**
 * This indicates that a record label (company) owns or has the right to use an imprint.
 *
 * @link https://musicbrainz.org/relationship/23f8c592-006d-4214-9080-c4e5000c05d7
 */
class Imprint extends BusinessAssociation
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('imprint');
    }
}
