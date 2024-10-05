<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Video;

use MusicBrainz\Relation\Type\Artist\Recording\Video;
use MusicBrainz\Value\Name;

/**
 * This indicates that an artist appears on a music video, but doesn't actually perform on the audio track.
 *
 * @link https://musicbrainz.org/relationship/601fc03e-1058-4ee6-a546-b914d55aa6ba
 */
class VideoAppearance extends Video
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('video appearance');
    }
}
