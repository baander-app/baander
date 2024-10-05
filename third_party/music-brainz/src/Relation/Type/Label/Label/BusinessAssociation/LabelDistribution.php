<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Label\BusinessAssociation;

use MusicBrainz\Relation\Type\Label\Label\BusinessAssociation;
use MusicBrainz\Value\Name;

/**
 * This describes a situation where one label is distributing (part of) another label's catalog, in a country/region of the world, during a period of time.
 *
 * @link https://musicbrainz.org/relationship/e0636054-32b7-4dd5-97a9-6e5664d443bc
 */
class LabelDistribution extends BusinessAssociation
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('label distribution');
    }
}
