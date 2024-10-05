<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Work\Work\OtherVersion;

use MusicBrainz\Relation\Type\Work\Work\OtherVersion;
use MusicBrainz\Value\Name;

/**
 * This links different revisions of the same work.
 *
 * @link https://musicbrainz.org/relationship/4d0d6491-3c41-42c6-883f-d6c7e825b052
 */
class RevisionOf extends OtherVersion
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('revision of');
    }
}
