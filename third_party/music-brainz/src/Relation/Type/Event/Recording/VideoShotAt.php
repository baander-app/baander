<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Event\Recording;

use MusicBrainz\Relation\Type\Event\Recording;
use MusicBrainz\Value\Name;

/**
 * This indicates the video was shot at/during this event.
 *
 * @link https://musicbrainz.org/relationship/0c050cf9-885b-49fe-9a12-5ca04e2db8da
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
