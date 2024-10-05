<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Recording\Recording\OtherVersions;

use MusicBrainz\Relation\Type\Recording\Recording\OtherVersions;
use MusicBrainz\Value\Name;

/**
 * This relationship type is deprecated and should not be used.
 *
 * @link https://musicbrainz.org/relationship/f5f41b82-ecc7-488e-adf3-12356885d724
 */
class FirstTrackRelease extends OtherVersions
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('first track release');
    }
}
