<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Url\GetTheMusic;

use MusicBrainz\Relation\Type\Artist\Url\GetTheMusic;
use MusicBrainz\Value\Name;

/**
 * This relationship type is used to link an artist to a site where music can be legally streamed for a subscription fee, such as Tidal. If the site allows free streaming, use "free streaming" instead.
 *
 * @link https://musicbrainz.org/relationship/63cc5d1f-f096-4c94-a43f-ecb32ea94161
 */
class Streaming extends GetTheMusic
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('streaming');
    }
}
