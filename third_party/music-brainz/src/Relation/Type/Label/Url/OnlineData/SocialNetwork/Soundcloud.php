<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Url\OnlineData\SocialNetwork;

use MusicBrainz\Relation\Type\Label\Url\OnlineData\SocialNetwork;
use MusicBrainz\Value\Name;

/**
 * This links a label to its profile at SoundCloud.
 *
 * @link https://musicbrainz.org/relationship/a31d05ba-3b82-47b2-ab8b-1fe73b5459e2
 */
class Soundcloud extends SocialNetwork
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('soundcloud');
    }
}
