<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Url\OnlineData\SocialNetwork;

use MusicBrainz\Relation\Type\Artist\Url\OnlineData\SocialNetwork;
use MusicBrainz\Value\Name;

/**
 * This links an artist to the equivalent entry at PureVolume.
 *
 * @link https://musicbrainz.org/relationship/b6f02157-a9d3-4f24-9057-0675b2dbc581
 */
class Purevolume extends SocialNetwork
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('purevolume');
    }
}
