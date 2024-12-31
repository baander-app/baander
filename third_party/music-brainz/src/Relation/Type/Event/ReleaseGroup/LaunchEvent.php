<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Event\ReleaseGroup;

use MusicBrainz\Relation\Type\Event\ReleaseGroup;
use MusicBrainz\Value\Name;

/**
 * Links a release group with a launch event for it.
 *
 * @link https://musicbrainz.org/relationship/5db276a9-15d2-3955-b5d9-c11f27fd8b44
 */
class LaunchEvent extends ReleaseGroup
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('launch event');
    }
}
