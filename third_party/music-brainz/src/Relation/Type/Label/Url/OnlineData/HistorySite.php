<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Url\OnlineData;

use MusicBrainz\Relation\Type\Label\Url\OnlineData;
use MusicBrainz\Value\Name;

/**
 * This links to a site describing relevant details about a label's history.
 *
 * @link https://musicbrainz.org/relationship/5261835c-0c23-4a63-94db-ad3a86bda846
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
