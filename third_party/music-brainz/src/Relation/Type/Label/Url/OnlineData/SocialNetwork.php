<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Url\OnlineData;

use MusicBrainz\Relation\Type\Label\Url\OnlineData;
use MusicBrainz\Value\Name;

/**
 * A social network page is a label's own page on a social network which only people involved with the label can post content to. Examples include Facebook pages, and accounts on Twitter, Instagram and Flickr.
 *
 * @link https://musicbrainz.org/relationship/5d217d99-bc05-4a76-836d-c91eec4ba818
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
