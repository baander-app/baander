<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Series\Url\SocialNetwork;

use MusicBrainz\Relation\Type\Series\Url\SocialNetwork;
use MusicBrainz\Value\Name;

/**
 * This links a series (most commonly, but not necessarily always, a music festival) to its official page at SoundCloud.
 *
 * @link https://musicbrainz.org/relationship/4789521b-57b9-4689-9644-46de63190f66
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
