<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Series\Url;

use MusicBrainz\Relation\Type\Series\Url;
use MusicBrainz\Value\Name;

/**
 * This indicates where a feed for this podcast can be found.
 *
 * @link https://musicbrainz.org/relationship/5ce55509-47a5-4374-a1c6-a68fd377bddf
 */
class PodcastFeed extends Url
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('podcast feed');
    }
}
