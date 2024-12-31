<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Url\OnlineData\SocialNetwork;

use MusicBrainz\Relation\Type\Label\Url\OnlineData\SocialNetwork;
use MusicBrainz\Value\Name;

/**
 * This links a label to a channel, playlist, or user page on a video sharing site containing videos curated by it.
 *
 * @link https://musicbrainz.org/relationship/20ad367c-cba0-4c02-bd61-2df3ae8cc799
 */
class VideoChannel extends SocialNetwork
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
