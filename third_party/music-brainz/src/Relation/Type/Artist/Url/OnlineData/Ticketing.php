<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Url\OnlineData;

use MusicBrainz\Relation\Type\Artist\Url\OnlineData;
use MusicBrainz\Value\Name;

/**
 * This links an artist to a site where tickets can be purchased for their events.
 *
 * @link https://musicbrainz.org/relationship/34beaf28-cbdd-4bf7-bc41-e7de18135245
 */
class Ticketing extends OnlineData
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('ticketing');
    }
}
