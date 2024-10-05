<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Artist\PersonalRelationship;

use MusicBrainz\Relation\Type\Artist\Artist\PersonalRelationship;
use MusicBrainz\Value\Name;

/**
 * Indicates that two persons were romantically involved with each other without being married.
 *
 * @link https://musicbrainz.org/relationship/fd3927ba-fd51-4fa9-bcc2-e83637896fe8
 */
class InvolvedWith extends PersonalRelationship
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('involved with');
    }
}
