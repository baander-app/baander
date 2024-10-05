<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Series\Url;

use MusicBrainz\Relation\Type\Series\Url;
use MusicBrainz\Value\Name;

/**
 * A social network page is a page for a series on a social network which only people involved with the series can post content to. Examples include Facebook pages, and accounts on Twitter, Instagram and Flickr.
 *
 * @link https://musicbrainz.org/relationship/80d5e037-9aa7-3d80-80da-fb01d6dbc25b
 */
class SocialNetwork extends Url
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
