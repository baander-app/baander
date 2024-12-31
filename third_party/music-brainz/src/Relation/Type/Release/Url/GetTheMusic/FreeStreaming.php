<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Release\Url\GetTheMusic;

use MusicBrainz\Relation\Type\Release\Url\GetTheMusic;
use MusicBrainz\Value\Name;

/**
 * This relationship type is used to link a release to a site where the tracks can be legally streamed for free, such as Spotify.
 *
 * @link https://musicbrainz.org/relationship/08445ccf-7b99-4438-9f9a-fb9ac18099ee
 */
class FreeStreaming extends GetTheMusic
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('free streaming');
    }
}
