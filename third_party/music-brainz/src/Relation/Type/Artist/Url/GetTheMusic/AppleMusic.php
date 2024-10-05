<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Url\GetTheMusic;

use MusicBrainz\Relation\Type\Artist\Url\GetTheMusic;
use MusicBrainz\Value\Name;

/**
 * This links an artist to its page at Apple Music.
 *
 * @link https://musicbrainz.org/relationship/64785d6c-2eeb-4f86-9418-b6c2d6c53c13
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
