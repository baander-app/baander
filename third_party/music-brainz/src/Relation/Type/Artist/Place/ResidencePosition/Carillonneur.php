<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Place\ResidencePosition;

use MusicBrainz\Relation\Type\Artist\Place\ResidencePosition;
use MusicBrainz\Value\Name;

/**
 * This relationship links a carillonneur to the place(s) (most commonly religious buildings) at which they are the resident carillonneur.
 *
 * @link https://musicbrainz.org/relationship/f8920cb5-ae7f-465c-8128-d124a3eff3b9
 */
class Carillonneur extends ResidencePosition
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('carillonneur');
    }
}
