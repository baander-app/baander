<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Recording;

use MusicBrainz\Relation\Type\Place\Recording;
use MusicBrainz\Value\Name;

/**
 * This indicates the video was shot at this place.
 *
 * @link https://musicbrainz.org/relationship/07e6b4eb-5891-4646-b167-f96c5a4ccc77
 */
class VideoShotAt extends Recording
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('video shot at');
    }
}
