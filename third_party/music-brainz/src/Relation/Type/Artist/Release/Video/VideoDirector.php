<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Video;

use MusicBrainz\Relation\Type\Artist\Release\Video;
use MusicBrainz\Value\Name;

/**
 * This indicates the artist directed video on this release.
 *
 * @link https://musicbrainz.org/relationship/4d4a0a39-579f-489e-a3b3-bc7cf96e987b
 */
class VideoDirector extends Video
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('video director');
    }
}
