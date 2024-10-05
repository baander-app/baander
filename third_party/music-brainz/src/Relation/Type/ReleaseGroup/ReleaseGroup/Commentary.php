<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\ReleaseGroup\ReleaseGroup;

use MusicBrainz\Relation\Type\ReleaseGroup\ReleaseGroup;
use MusicBrainz\Value\Name;

/**
 * This links a release group to another containing official commentary for it (usually the artist talking about each specific track in an album).
 *
 * @link https://musicbrainz.org/relationship/10476d36-e9f0-40ac-9318-339399bdeadc
 */
class Commentary extends ReleaseGroup
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('commentary');
    }
}
