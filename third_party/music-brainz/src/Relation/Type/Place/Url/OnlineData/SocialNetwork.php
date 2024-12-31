<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Url\OnlineData;

use MusicBrainz\Relation\Type\Place\Url\OnlineData;
use MusicBrainz\Value\Name;

/**
 * A social network page is a place's own page on a social network which only people involved with the place can post content to. Examples include Facebook pages, and accounts on Twitter, Instagram and Flickr.
 *
 * @link https://musicbrainz.org/relationship/040de4d5-ace5-4cfb-8a45-95c5c73bce01
 */
class SocialNetwork extends OnlineData
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('social network');
    }
}
