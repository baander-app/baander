<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Url\OnlineData;

use MusicBrainz\Relation\Type\Artist\Url\OnlineData;
use MusicBrainz\Value\Name;

/**
 * A social network page is an artist's own profile page on a social network which only they (or their management) can post content to. Other people can create their own profiles and interact with the artist, for example by adding them as a friend or by commenting on the things that they post. Examples include Facebook pages and profiles, Last.fm users and accounts on Twitter, Instagram and Flickr.
 *
 * @link https://musicbrainz.org/relationship/99429741-f3f6-484b-84f8-23af51991770
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
