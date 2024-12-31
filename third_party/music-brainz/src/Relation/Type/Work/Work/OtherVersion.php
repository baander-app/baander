<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Work\Work;

use MusicBrainz\Relation\Type\Work\Work;
use MusicBrainz\Value\Name;

/**
 * This links two versions of a work.
 *
 * @link https://musicbrainz.org/relationship/7440b539-19ab-4243-8c03-4f5942ca2218
 */
class OtherVersion extends Work
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('other version');
    }
}
