<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Genre\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Genre\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * Points to the Wikidata page for this genre, and will be used to fetch Wikipedia summaries.
 *
 * @link https://musicbrainz.org/relationship/11a13c3b-15cb-4c1c-accc-0417f7f2019b
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
