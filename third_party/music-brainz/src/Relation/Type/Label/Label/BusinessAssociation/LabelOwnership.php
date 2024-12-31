<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Label\BusinessAssociation;

use MusicBrainz\Relation\Type\Label\Label\BusinessAssociation;
use MusicBrainz\Value\Name;

/**
 * This describes a situation where one label is (or was) a subsidiary of another label, during a given period of time. This should be used either to describe the fact a label is a subdivision of another one, or, through corporate acquisition of the former label, has become a subdivision of another one.
 *
 * @link https://musicbrainz.org/relationship/ab7a9be0-c060-4852-8f2e-4faf5b33231e
 */
class LabelOwnership extends BusinessAssociation
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('label ownership');
    }
}
