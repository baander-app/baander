<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Release\Url\GetTheMusic;

use MusicBrainz\Relation\Type\Release\Url\GetTheMusic;
use MusicBrainz\Value\Name;

/**
 * This relationship type is used to link a release to a site where the tracks can be legally streamed for a subscription fee, such as Tidal. If the site allows free streaming, use "free streaming" instead.
 *
 * @link https://musicbrainz.org/relationship/320adf26-96fa-4183-9045-1f5f32f833cb
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
