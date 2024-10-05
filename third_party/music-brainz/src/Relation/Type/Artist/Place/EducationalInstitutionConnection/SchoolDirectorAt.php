<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Place\EducationalInstitutionConnection;

use MusicBrainz\Relation\Type\Artist\Place\EducationalInstitutionConnection;
use MusicBrainz\Value\Name;

/**
 * This relationship links a person to the music school(s) they directed.
 *
 * @link https://musicbrainz.org/relationship/0dafbb5d-26eb-4a52-bb0c-af13ffe36544
 */
class SchoolDirectorAt extends EducationalInstitutionConnection
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('school director at');
    }
}
