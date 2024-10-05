<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Event\Url;

use MusicBrainz\Relation\Type\Event\Url;
use MusicBrainz\Value\Name;

/**
 * This links an event to a channel, playlist, or user page on a video sharing site containing videos curated by it.
 *
 * @link https://musicbrainz.org/relationship/1f3df2eb-3d0b-44f1-9599-1309c692bc7c
 */
class VideoChannel extends Url
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
