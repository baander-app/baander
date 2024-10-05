<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Url\OnlineData;

use MusicBrainz\Relation\Type\Place\Url\OnlineData;
use MusicBrainz\Value\Name;

/**
 * This links a place to a channel, playlist, or user page on a video sharing site containing videos curated by it.
 *
 * @link https://musicbrainz.org/relationship/e5c5a0f6-9581-44d8-a5fb-d3688254dc9f
 */
class VideoChannel extends OnlineData
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('video channel');
    }
}
