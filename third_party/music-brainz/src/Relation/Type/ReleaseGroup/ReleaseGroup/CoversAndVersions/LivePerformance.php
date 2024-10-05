<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\ReleaseGroup\ReleaseGroup\CoversAndVersions;

use MusicBrainz\Relation\Type\ReleaseGroup\ReleaseGroup\CoversAndVersions;
use MusicBrainz\Value\Name;

/**
 * This is used to indicate that a release group is a live performance of a studio release group.
 *
 * @link https://musicbrainz.org/relationship/62beff0a-679c-43f3-8fe6-f6c8ed8581e4
 */
class LivePerformance extends CoversAndVersions
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('live performance');
    }
}
