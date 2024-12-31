<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Url\GetTheMusic;

use MusicBrainz\Relation\Type\Label\Url\GetTheMusic;
use MusicBrainz\Value\Name;

/**
 * This links a label to its page at Apple Music.
 *
 * @link https://musicbrainz.org/relationship/debf36e1-b0fa-4e6c-987e-4248bf050fd8
 */
class AppleMusic extends GetTheMusic
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('apple music');
    }
}
