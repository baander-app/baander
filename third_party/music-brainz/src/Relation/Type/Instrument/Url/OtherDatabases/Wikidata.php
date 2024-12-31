<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Instrument\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Instrument\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * Points to the Wikidata page for this instrument, and will be used to fetch Wikipedia summaries.
 *
 * @link https://musicbrainz.org/relationship/1486fccd-cf59-35e4-9399-b50e2b255877
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
