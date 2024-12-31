<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Url\GetTheMusic;

use MusicBrainz\Relation\Type\Artist\Url\GetTheMusic;
use MusicBrainz\Value\Name;

/**
 * This links an artist to its channel at YouTube Music.
 *
 * @link https://musicbrainz.org/relationship/631712a0-7525-42ba-b7a3-605aa7a238c4
 */
class YoutubeMusic extends GetTheMusic
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('youtube music');
    }
}
