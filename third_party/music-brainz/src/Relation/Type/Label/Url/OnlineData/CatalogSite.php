<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Url\OnlineData;

use MusicBrainz\Relation\Type\Label\Url\OnlineData;
use MusicBrainz\Value\Name;

/**
 * This links to a catalog or list of records published by the label.
 *
 * @link https://musicbrainz.org/relationship/5ac35a29-d29b-4390-b279-587bcd42fc73
 */
class CatalogSite extends OnlineData
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('catalog site');
    }
}
