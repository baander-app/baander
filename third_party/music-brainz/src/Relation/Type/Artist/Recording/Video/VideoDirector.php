<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Video;

use MusicBrainz\Relation\Type\Artist\Recording\Video;
use MusicBrainz\Value\Name;

/**
 * This indicates the artist was the director of this video recording.
 *
 * @link https://musicbrainz.org/relationship/578ee04d-3227-4335-ba2c-11e8ba420e0b
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
