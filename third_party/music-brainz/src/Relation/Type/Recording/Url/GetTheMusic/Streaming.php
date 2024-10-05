<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Recording\Url\GetTheMusic;

use MusicBrainz\Relation\Type\Recording\Url\GetTheMusic;
use MusicBrainz\Value\Name;

/**
 * This relationship type is used to link a recording to a site where it can be legally streamed for a subscription fee, such as Tidal. If the site allows free streaming, use "free streaming" instead.
 *
 * @link https://musicbrainz.org/relationship/b5f3058a-666c-406f-aafb-f9249fc7b122
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
