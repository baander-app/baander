<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Label\Ownership;

use MusicBrainz\Relation\Type\Artist\Label\Ownership;
use MusicBrainz\Value\Name;

/**
 * This indicates a personal production label for an artist. A personal label is a small label (usually a subdivision of a larger one) that exclusively handles releases by that artist.
 *
 * @link https://musicbrainz.org/relationship/fe16f2bd-d324-435a-8076-bcf43b805bd9
 */
class PersonalLabel extends Ownership
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('personal label');
    }
}
