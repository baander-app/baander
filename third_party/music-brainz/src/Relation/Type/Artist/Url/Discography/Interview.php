<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Url\Discography;

use MusicBrainz\Relation\Type\Artist\Url\Discography;
use MusicBrainz\Value\Name;

/**
 * This links an artist to an URL containing an interview with that artist.
 *
 * @link https://musicbrainz.org/relationship/1f171391-1f98-4f45-b191-038ec3b12395
 */
class Interview extends Discography
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('interview');
    }
}
