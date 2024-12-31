<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Recording\Recording\OtherVersions;

use MusicBrainz\Relation\Type\Recording\Recording\OtherVersions;
use MusicBrainz\Value\Name;

/**
 * This is used to link a karaoke version of a song to the original song. A karaoke version is a version of the song with the main vocals removed, designed to be used for karaoke. These are generally produced from the original masters by muting the main vocal track or by using post-processing filters to remove the vocals. Karaoke versions can be found labelled in numerous different ways other than "karaoke": instrumental (even if backing vocals are still present), off vocal, backing track, etc.
 *
 * @link https://musicbrainz.org/relationship/39a08d0e-26e4-44fb-ae19-906f5fe9435d
 */
class Karaoke extends OtherVersions
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('karaoke');
    }
}
