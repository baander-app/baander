<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Area\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Area\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * Points to the Wikidata page for this area, and will be used to fetch Wikipedia summaries.
 *
 * @link https://musicbrainz.org/relationship/85c5256f-aef1-484f-979a-42007218a1c2
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
