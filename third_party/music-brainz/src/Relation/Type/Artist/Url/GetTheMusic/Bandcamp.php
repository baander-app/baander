<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Url\GetTheMusic;

use MusicBrainz\Relation\Type\Artist\Url\GetTheMusic;
use MusicBrainz\Value\Name;

/**
 * This links an artist to its page at Bandcamp.
 *
 * @link https://musicbrainz.org/relationship/c550166e-0548-4a18-b1d4-e2ae423a3e88
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
