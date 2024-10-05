<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\ReleaseGroup;

use MusicBrainz\Relation\Type\Artist\ReleaseGroup;
use MusicBrainz\Value\Name;

/**
 * This credits a person or agency who provided some kind of general creative inspiration during the recording of this release group, without actually contributing to the writing or performance.
 *
 * @link https://musicbrainz.org/relationship/e035ac25-a2ff-48a6-9fb6-077692c67241
 */
class CreativeDirection extends ReleaseGroup
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('creative direction');
    }
}
