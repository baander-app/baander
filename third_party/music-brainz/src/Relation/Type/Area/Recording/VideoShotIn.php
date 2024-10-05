<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Area\Recording;

use MusicBrainz\Relation\Type\Area\Recording;
use MusicBrainz\Value\Name;

/**
 * This indicates the video was shot in this area.
 *
 * @link https://musicbrainz.org/relationship/d92ee325-0d37-4d1c-aea1-436f36e13565
 */
class VideoShotIn extends Recording
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('video shot in');
    }
}
