<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Genre\Url\GetTheMusic;

use MusicBrainz\Relation\Type\Genre\Url\GetTheMusic;
use MusicBrainz\Value\Name;

/**
 * This links a genre to its page at Bandcamp.
 *
 * @link https://musicbrainz.org/relationship/ad28869f-0f9e-4bd5-b786-70125cc69c3c
 */
class Bandcamp extends GetTheMusic
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('bandcamp');
    }
}
