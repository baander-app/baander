<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Place\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * Points to the Wikidata page for this place, and will be used to fetch Wikipedia summaries.
 *
 * @link https://musicbrainz.org/relationship/e6826618-b410-4b8d-b3b5-52e29eac5e1f
 */
class Wikidata extends OtherDatabases
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('wikidata');
    }
}
