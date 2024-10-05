<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Url\OnlineData\SocialNetwork;

use MusicBrainz\Relation\Type\Artist\Url\OnlineData\SocialNetwork;
use MusicBrainz\Value\Name;

/**
 * This relationship type links an artist to their Myspace page.
 *
 * @link https://musicbrainz.org/relationship/bac47923-ecde-4b59-822e-d08f0cd10156
 */
class Myspace extends SocialNetwork
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('myspace');
    }
}
