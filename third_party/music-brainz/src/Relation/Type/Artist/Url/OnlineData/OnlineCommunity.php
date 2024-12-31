<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Url\OnlineData;

use MusicBrainz\Relation\Type\Artist\Url\OnlineData;
use MusicBrainz\Value\Name;

/**
 * This relationship type links an artist to their online community page(s). An online community is a group where any member can comment and contribute content. The artist may or may not be a member of the group. Examples include forums, LiveJournal communities and groups on Facebook, Last.fm and Flickr.
 *
 * @link https://musicbrainz.org/relationship/35b3a50f-bf0e-4309-a3b4-58eeed8cee6a
 */
class OnlineCommunity extends OnlineData
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('online community');
    }
}
