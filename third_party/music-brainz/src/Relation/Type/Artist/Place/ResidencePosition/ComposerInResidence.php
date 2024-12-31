<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Place\ResidencePosition;

use MusicBrainz\Relation\Type\Artist\Place\ResidencePosition;
use MusicBrainz\Value\Name;

/**
 * This links a place (often a concert hall or educational institution) to a composer who has a composer-in-residence position with it.
 *
 * @link https://musicbrainz.org/relationship/7f7d829b-6ba8-4f86-be90-c9372ef9a679
 */
class ComposerInResidence extends ResidencePosition
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('composer-in-residence');
    }
}
