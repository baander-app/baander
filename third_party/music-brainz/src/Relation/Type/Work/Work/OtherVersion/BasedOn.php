<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Work\Work\OtherVersion;

use MusicBrainz\Relation\Type\Work\Work\OtherVersion;
use MusicBrainz\Value\Name;

/**
 * This links two works, where the second work is based on music or text from the first, but isn't directly a revision or an arrangement of it.
 *
 * @link https://musicbrainz.org/relationship/6bb1df6b-57f3-434d-8a39-5dc363d2eb78
 */
class BasedOn extends OtherVersion
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('based on');
    }
}
