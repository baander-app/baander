<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Event\Url;

use MusicBrainz\Relation\Type\Event\Url;
use MusicBrainz\Value\Name;

/**
 * A social network page is an event's own page on a social network which only people involved with the event can post content to. Examples include Facebook pages and event entries, and accounts on Twitter, Instagram and Flickr.
 *
 * @link https://musicbrainz.org/relationship/68f5fcaa-b58c-3bfe-9b7c-75c2b56e839a
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
