<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Place\EducationalInstitutionConnection;

use MusicBrainz\Relation\Type\Artist\Place\EducationalInstitutionConnection;
use MusicBrainz\Value\Name;

/**
 * This relationship links a musician to the music school(s) they were educated at.
 *
 * @link https://musicbrainz.org/relationship/58e18f90-fb7d-41d8-a70d-8d750fb73617
 */
class StudiedAt extends EducationalInstitutionConnection
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('studied at');
    }
}
