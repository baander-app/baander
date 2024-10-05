<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Url\OnlineData;

use MusicBrainz\Relation\Type\Place\Url\OnlineData;
use MusicBrainz\Value\Name;

/**
 * This links to a site describing relevant details about a place's history.
 *
 * @link https://musicbrainz.org/relationship/271e2959-8dbd-45e2-933c-55cab4227b51
 */
class HistorySite extends OnlineData
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('history site');
    }
}
