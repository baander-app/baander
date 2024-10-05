<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\ReleaseGroup;

use MusicBrainz\Relation\Type\Artist\ReleaseGroup;
use MusicBrainz\Value\Name;

/**
 * This links a release group to an artist it is dedicated to. This is for dedications such as "This album is dedicated to the memory of Artist" - for tribute albums consisting of covers of the artist, use the tribute relationship.
 *
 * @link https://musicbrainz.org/relationship/b41e7530-cde4-459c-b8c5-dfef08fc8295
 */
class DedicatedTo extends ReleaseGroup
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('dedicated to');
    }
}
